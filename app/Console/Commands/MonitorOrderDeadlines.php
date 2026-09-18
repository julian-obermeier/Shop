<?php
namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderDay;
use App\Models\ProofSubmission;
use App\Models\UserNotification;
use App\Services\NotificationService;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorOrderDeadlines extends Command
{
    protected $signature='orders:deadlines';
    protected $description='Monitor start, proof, resubmission and shipping deadlines';

    public function handle(OrderService $orders, NotificationService $notifications): int
    {
        $this->expireMissedStarts($notifications);
        $this->expireResubmissionDeadlines($orders,$notifications);
        $this->invalidateMissedProofWindows($orders,$notifications);
        $this->monitorShippingDeadlines($notifications);

        return self::SUCCESS;
    }

    private function expireMissedStarts(NotificationService $notifications): void
    {
        $today=CarbonImmutable::today('Europe/Berlin');

        Order::with('user')
            ->whereIn('status',['approved','waiting_start'])
            ->whereNotNull('confirmed_start_date')
            ->whereDate('confirmed_start_date','<',$today->toDateString())
            ->orderBy('id')
            ->chunkById(100,function($rows) use($notifications){
                foreach($rows as $row){
                    DB::transaction(function() use($row,$notifications){
                        $order=Order::with('user')->whereKey($row->id)->lockForUpdate()->first();
                        if(!$order || !in_array($order->status,['approved','waiting_start'],true)) return;

                        $hasStartProof=$order->days()
                            ->where('day_number',0)
                            ->where('series_number',$order->series_number)
                            ->whereHas('proofs')
                            ->exists();

                        if($hasStartProof) return;

                        $from=$order->status;
                        $order->update([
                            'status'=>'not_started',
                            'final_compensation'=>0,
                            'completed_at'=>now(),
                            'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                            'last_reliability_issue'=>'Verbindlichen Start nicht angetreten',
                        ]);

                        $order->statusHistory()->create([
                            'changed_by'=>null,
                            'from_status'=>$from,
                            'to_status'=>'not_started',
                            'reason'=>'Verpflichtendes Startfoto am bestätigten Startdatum nicht eingereicht',
                        ]);

                        $this->resetProbation($order->user_id);

                        $notifications->send(
                            $order->user,
                            'order_not_started',
                            'Auftrag nicht angetreten',
                            'Auftrag #'.$order->order_number.' wurde beendet, weil der verpflichtende Startnachweis am bestätigten Starttag nicht eingereicht wurde.',
                            route('orders.show',$order),
                            ['order_id'=>$order->id]
                        );
                    });
                }
            });
    }

    private function expireResubmissionDeadlines(OrderService $orders, NotificationService $notifications): void
    {
        ProofSubmission::with('orderDay.order.user')
            ->where('review_status','rejected')
            ->whereNotNull('resubmit_due_at')
            ->where('resubmit_due_at','<',now())
            ->orderBy('id')
            ->chunkById(100,function($proofs) use($orders,$notifications){
                foreach($proofs as $row){
                    DB::transaction(function() use($row,$orders,$notifications){
                        $proof=ProofSubmission::with('orderDay.order.user')->whereKey($row->id)->lockForUpdate()->first();
                        if(!$proof || $proof->review_status!=='rejected' || !$proof->resubmit_due_at || $proof->resubmit_due_at->isFuture()) return;

                        $newer=$proof->orderDay->proofs()
                            ->where('window_key',$proof->window_key)
                            ->where('id','>',$proof->id)
                            ->whereIn('review_status',['pending','accepted'])
                            ->exists();

                        $proof->update(['resubmit_due_at'=>null]);
                        if($newer) return;

                        $day=$proof->orderDay;
                        if(!$day->counts_toward_series || in_array($day->status,['invalid','accepted'],true)) return;

                        $orders->invalidateDay($day,'2-Stunden-Nachreichfrist nach Ablehnung versäumt');

                        $notifications->send(
                            $proof->orderDay->order->user,
                            'proof_resubmission_missed',
                            'Nachreichfrist versäumt',
                            'Die 2-Stunden-Nachreichfrist für einen Nachweis zu Auftrag #'.$proof->orderDay->order->order_number.' wurde versäumt. Der betroffene Tag wird nach den Auftragsregeln behandelt.',
                            route('orders.show',$proof->orderDay->order),
                            ['order_id'=>$proof->orderDay->order_id,'order_day_id'=>$day->id]
                        );
                    });
                }
            });
    }

    private function invalidateMissedProofWindows(OrderService $orders, NotificationService $notifications): void
    {
        $now=CarbonImmutable::now('Europe/Berlin');

        OrderDay::with(['order.user','proofs'])
            ->where('day_number','>',0)
            ->where('counts_toward_series',true)
            ->where('status','open')
            ->whereDate('date','<=',$now->toDateString())
            ->whereHas('order',fn($q)=>$q->where('status','active'))
            ->orderBy('id')
            ->chunkById(100,function($days) use($orders,$notifications,$now){
                foreach($days as $day){
                    $order=$day->order;
                    if((int)$day->series_number!==(int)$order->series_number) continue;

                    $windows=data_get($order->current_requirements ?: $order->offer_snapshot,'proof_requirements',[]);
                    if(!is_array($windows)) continue;

                    foreach($windows as $window){
                        $required=(int)($window['required_images']??0);
                        if($required<=0) continue;

                        $key=(string)($window['key']??'default');
                        $start=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['start']??'00:00'),'Europe/Berlin');
                        $end=CarbonImmutable::parse($day->date->format('Y-m-d').' '.($window['end']??'23:59'),'Europe/Berlin');
                        if($end->lt($start)) $end=$end->addDay();
                        if($now->lte($end)) continue;

                        $submitted=$day->proofs
                            ->where('window_key',$key)
                            ->whereIn('review_status',['pending','accepted'])
                            ->count();

                        if($submitted >= $required) continue;

                        $activeResubmission=$day->proofs
                            ->where('window_key',$key)
                            ->where('review_status','rejected')
                            ->filter(fn($proof)=>$proof->resubmit_due_at && $proof->resubmit_due_at->isFuture())
                            ->isNotEmpty();

                        if($activeResubmission) continue;

                        $orders->invalidateDay($day,'Verpflichtetes Nachweisfenster „'.($window['label']??$key).'“ versäumt');

                        $notifications->send(
                            $order->user,
                            'proof_window_missed',
                            'Nachweisfenster versäumt',
                            'Bei Auftrag #'.$order->order_number.' wurde das verpflichtende Nachweisfenster „'.($window['label']??$key).'“ nicht vollständig erfüllt. Der Tag wird nach den festgelegten Ersatz-/Neustartregeln behandelt.',
                            route('orders.show',$order),
                            ['order_id'=>$order->id,'order_day_id'=>$day->id,'window_key'=>$key]
                        );
                        break;
                    }
                }
            });
    }

    private function monitorShippingDeadlines(NotificationService $notifications): void
    {
        $orders=Order::with('user')
            ->whereIn('status',['waiting_shipping','shipping_overdue'])
            ->whereNotNull('shipping_due_at')
            ->whereDoesntHave('shipment')
            ->get();

        foreach($orders as $order){
            if($order->shipping_due_at->isPast() && $order->status!=='shipping_overdue'){
                DB::transaction(function() use($order){
                    $fresh=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                    if($fresh->status!=='waiting_shipping') return;

                    $fresh->update([
                        'status'=>'shipping_overdue',
                        'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                        'last_reliability_issue'=>'24-Stunden-Versandfrist überschritten',
                    ]);
                    $fresh->statusHistory()->create([
                        'changed_by'=>null,
                        'from_status'=>'waiting_shipping',
                        'to_status'=>'shipping_overdue',
                        'reason'=>'Versandfrist automatisch überschritten',
                    ]);
                    $this->resetProbation($fresh->user_id);
                });

                $notifications->send(
                    $order->user,
                    'shipping_overdue',
                    'Versandfrist überschritten',
                    'Die 24-Stunden-Versandfrist für Auftrag #'.$order->order_number.' ist abgelaufen. Der Versand ist weiterhin möglich; die Verspätung wurde in der Zuverlässigkeit berücksichtigt.',
                    route('orders.show',$order),
                    ['order_id'=>$order->id]
                );
                continue;
            }

            if(
                $order->status==='waiting_shipping'
                && $order->shipping_due_at->isFuture()
                && $order->shipping_due_at->lte(now()->addHours(6))
            ){
                $exists=UserNotification::where('user_id',$order->user_id)
                    ->where('type','shipping_due_soon')
                    ->where('data->order_id',$order->id)
                    ->exists();

                if(!$exists){
                    $notifications->send(
                        $order->user,
                        'shipping_due_soon',
                        'Versandfrist läuft bald ab',
                        'Für Auftrag #'.$order->order_number.' endet die Versandfrist am '.$order->shipping_due_at->timezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr.',
                        route('orders.show',$order),
                        ['order_id'=>$order->id]
                    );
                }
            }
        }
    }

    private function resetProbation(int $userId): void
    {
        DB::table('user_restrictions')
            ->where('user_id',$userId)
            ->where('active',true)
            ->update(['successful_count'=>0,'progress_reset_at'=>now()]);
    }
}
