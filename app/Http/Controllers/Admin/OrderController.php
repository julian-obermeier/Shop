<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q=Order::with('user','offer','shipment','precheck');
        if($request->filled('status')) $q->where('status',$request->status);
        $orders=$q->latest()->paginate(30)->withQueryString();
        return view('admin.orders.index',compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load(
            'user','offer','options','fieldValues','days.proofs','statusHistory',
            'precheck','shipment.evidences','goodsReceipt','goodsInspection','returnRequest','conversation'
        );
        return view('admin.orders.show',compact('order'));
    }

    public function rejectRequest(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_unless(in_array($order->status,['requested','awaiting_date_confirmation'],true),422,'Nur eine noch nicht bestätigte Auftragsanfrage kann auf diese Weise abgelehnt werden.');

        $data=$request->validate([
            'reason'=>['nullable','string','max:1000'],
        ]);

        $before=$order->toArray();
        $from=$order->status;

        $order->update([
            'status'=>'request_rejected',
        ]);

        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>'request_rejected',
            'reason'=>$data['reason']??'Auftragsanfrage durch Admin abgelehnt',
        ]);

        $audit->log('order.request.rejected',$order,$before,$order->fresh()->toArray());

        $notifications->send(
            $order->user,
            'order_request_rejected',
            'Auftragsanfrage abgelehnt',
            'Deine Anfrage für Auftrag #'.$order->order_number.' wurde abgelehnt.'.(!empty($data['reason'])?' Grund: '.$data['reason']:''),
            route('orders.show',$order)
        );

        return back()->with('success','Auftragsanfrage wurde abgelehnt.');
    }

    public function reopenRequest(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($order->status==='request_rejected',422,'Nur eine abgelehnte Auftragsanfrage kann wieder geöffnet werden.');

        $before=$order->toArray();

        $order->update([
            'status'=>'requested',
            'completed_at'=>null,
        ]);

        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>'request_rejected',
            'to_status'=>'requested',
            'reason'=>'Abgelehnte Auftragsanfrage durch Admin wieder geöffnet',
        ]);

        $audit->log('order.request.reopened',$order,$before,$order->fresh()->toArray());

        $notifications->send(
            $order->user,
            'order_request_reopened',
            'Auftragsanfrage wieder geöffnet',
            'Deine Anfrage für Auftrag #'.$order->order_number.' wurde vom Admin wieder auf offen gesetzt.',
            route('orders.show',$order)
        );

        return back()->with('success','Auftragsanfrage wurde wieder geöffnet.');
    }

    public function approve(Request $request, Order $order, OrderService $orders, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate(['start_date'=>['nullable','date']]);
        $before=$order->toArray();
        $orders->approve($order,$request->user(),$data['start_date']??null);
        $audit->log('order.approved',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'order_approved',
            'Auftrag bestätigt',
            'Auftrag #'.$order->order_number.' wurde für den '.$order->fresh()->confirmed_start_date?->format('d.m.Y').' bestätigt.',
            route('orders.show',$order)
        );
        return back()->with('success','Auftrag und Startdatum wurden bestätigt.');
    }

    public function proposeDate(Request $request, Order $order, OrderService $orders, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate(['start_date'=>['required','date']]);
        $before=$order->toArray();
        $orders->proposeAlternateDate($order,$request->user(),$data['start_date']);
        $audit->log('order.date.proposed',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'order_date_proposed',
            'Neuer Starttermin vorgeschlagen',
            'Für Auftrag #'.$order->order_number.' wurde der '.$order->fresh()->proposed_start_date?->format('d.m.Y').' vorgeschlagen. Bitte bestätige den Termin.',
            route('orders.show',$order)
        );
        return back()->with('success','Alternativer Termin wurde vorgeschlagen.');
    }

    public function status(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_if($order->isTerminal(),422,'Ein endgültig beendeter Auftrag kann über diese Aktion nicht wieder geöffnet oder verändert werden.');

        $data=$request->validate([
            'status'=>['required','in:paused,cancelled,rejected'],
            'reason'=>['required','string','max:1000'],
            'compensation_amount'=>['nullable','numeric','min:0'],
        ]);

        $before=$order->toArray();
        $from=$order->status;

        DB::transaction(function() use($request,$order,$data,$from){
            if($data['status']==='paused'){
                abort_unless($from==='active',422,'Nur ein aktiver Auftrag kann pausiert werden.');
                $order->days()->where('series_number',$order->series_number)->update(['counts_toward_series'=>false]);
                $order->update(['status'=>'paused','paused_at'=>now(),'paused_from_status'=>$from]);
            } else {
                $amount=round((float)($data['compensation_amount']??0),2);
                abort_if($amount>(float)$order->compensation_total,422,'Die Abbruchvergütung darf die vereinbarte Gesamtvergütung nicht überschreiten.');
                $order->update([
                    'status'=>$data['status'],
                    'final_compensation'=>$amount,
                    'completed_at'=>now(),
                ]);

                if($amount>0){
                    $wallet=$order->user->walletAccount()->firstOrCreate([]);
                    $wallet->entries()->create([
                        'order_id'=>$order->id,
                        'bucket'=>'available',
                        'entry_type'=>'admin_abort_compensation',
                        'amount'=>$amount,
                        'reference'=>$order->order_number,
                        'description'=>'Vergütung nach Adminabbruch',
                        'metadata'=>['reason'=>$data['reason']],
                    ]);
                }
            }

            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>$from,
                'to_status'=>$data['status'],
                'reason'=>$data['reason'],
            ]);
        });

        $audit->log('order.status.changed',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'order_status',
            'Auftragsstatus aktualisiert',
            'Auftrag #'.$order->order_number.' steht jetzt auf '.strtoupper(str_replace('_',' ',$data['status'])).'. Grund: '.$data['reason'],
            route('orders.show',$order)
        );

        return back()->with('success','Status wurde geändert.');
    }

    public function resume(Request $request, Order $order, OrderService $orders, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($order->status==='paused',422,'Dieser Auftrag ist nicht pausiert.');
        abort_unless($order->user->status==='active',422,'Das Konto der Anbieterin muss vor der Auftragsfortsetzung wieder aktiviert werden.');

        $data=$request->validate([
            'activation_date'=>['nullable','date','after_or_equal:today'],
        ]);

        $before=$order->toArray();

        DB::transaction(function() use($order,$request,$orders,$data){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedUser=User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $order->setRelation('user',$lockedUser);

            $origin=$order->paused_from_status ?: 'active';

            if($origin==='active'){
                $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
                $series=(int)$order->series_number+1;
                $start=now('Europe/Berlin')->startOfDay()->addDay();

                $order->update([
                    'status'=>'active',
                    'series_number'=>$series,
                    'series_interruptions'=>0,
                    'paused_at'=>null,
                    'paused_from_status'=>null,
                    'start_date'=>$start->toDateString(),
                    'end_date'=>$start->copy()->addDays($duration-1)->toDateString(),
                ]);

                for($i=1;$i<=$duration;$i++){
                    $order->days()->create([
                        'series_number'=>$series,
                        'day_number'=>$i,
                        'date'=>$start->copy()->addDays($i-1)->toDateString(),
                        'required_proofs'=>$orders->requiredProofCount($order),
                        'status'=>'open',
                        'counts_toward_series'=>true,
                    ]);
                }

                $reason='Pause beendet; laufende Trageserie beginnt erneut bei Tag 1.';
                $to='active';
                $orders->shiftSockFollowers($order);
            } elseif(in_array($origin,['waiting_shipping','shipping_overdue'],true)){
                $order->update([
                    'status'=>'waiting_shipping',
                    'paused_at'=>null,
                    'paused_from_status'=>null,
                    'shipping_due_at'=>now()->addHours(24),
                ]);
                $reason='Pause beendet; Versandphase mit neuer 24-Stunden-Frist fortgesetzt.';
                $to='waiting_shipping';
            } elseif(in_array($origin,['shipped','received','inspection','accepted'],true)){
                $order->update([
                    'status'=>$origin,
                    'paused_at'=>null,
                    'paused_from_status'=>null,
                ]);
                $reason='Pause beendet; Auftrag in Phase '.strtoupper(str_replace('_',' ',$origin)).' fortgesetzt.';
                $to=$origin;
            } elseif(in_array($origin,['approved','waiting_start'],true)){
                $activation=\Carbon\CarbonImmutable::parse(
                    $data['activation_date'] ?? now('Europe/Berlin')->toDateString(),
                    'Europe/Berlin'
                )->startOfDay();

                $series=(int)$order->series_number;

                if($origin==='waiting_start'){
                    $order->days()
                        ->where('series_number',$series)
                        ->where('day_number',0)
                        ->update(['counts_toward_series'=>false]);

                    $series++;
                }

                $order->update([
                    'status'=>'approved',
                    'series_number'=>$series,
                    'series_interruptions'=>0,
                    'paused_at'=>null,
                    'paused_from_status'=>null,
                    'confirmed_start_date'=>$activation->toDateString(),
                    'proposed_start_date'=>$activation->toDateString(),
                    'activation_date'=>null,
                    'start_date'=>null,
                    'end_date'=>null,
                ]);
                $reason='Pause beendet; neuer verbindlicher Aktivierungstag '.$activation->format('d.m.Y').' gesetzt.';
                $to='approved';
            } elseif(in_array($origin,['precheck','precheck_resubmit'],true)){
                $order->update([
                    'status'=>$origin,
                    'paused_at'=>null,
                    'paused_from_status'=>null,
                ]);
                $reason='Pause beendet; Vorprüfungsphase fortgesetzt.';
                $to=$origin;
            } else {
                abort(422,'Dieser pausierte Auftragszustand kann nicht automatisch fortgesetzt werden.');
            }

            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>'paused',
                'to_status'=>$to,
                'reason'=>$reason,
            ]);
        });

        $audit->log('order.resumed',$order,$before,$order->fresh()->toArray());

        $notifications->send(
            $order->user,
            'order_resumed',
            'Auftrag fortgesetzt',
            'Auftrag #'.$order->order_number.' wurde durch den Admin fortgesetzt.',
            route('orders.show',$order)
        );

        return back()->with('success','Auftrag wurde passend zu seiner vorherigen Phase fortgesetzt.');
    }

    public function updateRequirements(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_unless(
            in_array($order->status,[
                'precheck','precheck_resubmit','approved','waiting_start','active','paused',
                'waiting_shipping','shipping_overdue','shipped','received','inspection','accepted'
            ],true),
            422,
            'Nachträgliche verbindliche Anforderungen sind erst nach Annahme des Auftrags und nur bis zum endgültigen Abschluss möglich.'
        );

        $data=$request->validate([
            'requirement_text'=>['required','string','max:5000'],
            'effective_mode'=>['required','in:immediately,next_window,next_day,custom'],
            'effective_at'=>['nullable','date','required_if:effective_mode,custom'],
            'additional_compensation'=>['nullable','numeric','min:0'],
        ]);

        $effectiveAt=match($data['effective_mode']){
            'immediately'=>now('Europe/Berlin'),
            'next_window'=>$this->nextProofWindowAt($order),
            'next_day'=>now('Europe/Berlin')->startOfDay()->addDay(),
            'custom'=>\Carbon\CarbonImmutable::parse($data['effective_at'],'Europe/Berlin'),
        };

        $auditBefore=[
            'status'=>$order->status,
            'compensation_total'=>(float)$order->compensation_total,
            'requirements_effective_at'=>$order->requirements_effective_at?->toIso8601String(),
        ];

        $requirements=$order->current_requirements ?: $order->offer_snapshot;
        $requirements['admin_addition']=[
            'text'=>$data['requirement_text'],
            'effective_mode'=>$data['effective_mode'],
            'effective_at'=>$effectiveAt->toIso8601String(),
            'additional_compensation'=>(float)($data['additional_compensation']??0),
        ];

        $order->update([
            'current_requirements'=>$requirements,
            'requirements_effective_at'=>$effectiveAt,
            'compensation_total'=>(float)$order->compensation_total+(float)($data['additional_compensation']??0),
        ]);

        $fresh=$order->fresh();

        $audit->log('order.requirements.changed',$order,$auditBefore,[
            'status'=>$fresh->status,
            'compensation_total'=>(float)$fresh->compensation_total,
            'requirements_effective_at'=>$fresh->requirements_effective_at?->toIso8601String(),
            'effective_mode'=>$data['effective_mode'],
            'additional_compensation'=>(float)($data['additional_compensation']??0),
            'change_recorded'=>true,
        ]);
        $notifications->send(
            $order->user,
            'order_requirements_changed',
            'Auftragsanforderungen geändert',
            'Für Auftrag #'.$order->order_number.' wurden verbindliche Anforderungen geändert. Wirksam ab '.$effectiveAt->format('d.m.Y H:i').' Uhr.',
            route('orders.show',$order)
        );

        return back()->with('success','Die neuen Anforderungen wurden gespeichert und mitgeteilt.');
    }

    private function nextProofWindowAt(Order $order): \Carbon\CarbonImmutable
    {
        $now=\Carbon\CarbonImmutable::now('Europe/Berlin');
        $requirements=data_get($order->current_requirements ?: $order->offer_snapshot,'proof_requirements',[]);
        $days=$order->days()
            ->where('series_number',$order->series_number)
            ->where('day_number','>',0)
            ->where('counts_toward_series',true)
            ->whereDate('date','>=',$now->toDateString())
            ->orderBy('date')
            ->get();

        foreach($days as $day){
            foreach(is_array($requirements)?$requirements:[] as $window){
                $at=\Carbon\CarbonImmutable::parse(
                    $day->date->format('Y-m-d').' '.($window['start']??'00:00'),
                    'Europe/Berlin'
                );
                if($at->greaterThan($now)) return $at;
            }
        }

        return $now;
    }

}
