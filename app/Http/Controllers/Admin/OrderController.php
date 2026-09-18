<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\WalletService;
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
        abort_if($order->status==='completed',422,'Ein abgeschlossener Auftrag kann nicht wieder geöffnet oder verändert werden.');

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
                $order->update(['status'=>'paused','paused_at'=>now()]);
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

        $before=$order->toArray();
        DB::transaction(function() use($order,$request,$orders){
            $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
            $series=(int)$order->series_number+1;
            $start=now('Europe/Berlin')->startOfDay()->addDay();

            $order->update([
                'status'=>'active',
                'series_number'=>$series,
                'series_interruptions'=>0,
                'paused_at'=>null,
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

            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>'paused',
                'to_status'=>'active',
                'reason'=>'Pause beendet; Trageserie startet erneut bei Tag 1',
            ]);
            $orders->shiftSockFollowers($order);
        });

        $audit->log('order.resumed',$order,$before,$order->fresh()->toArray());
        $notifications->send($order->user,'order_resumed','Auftrag fortgesetzt','Auftrag #'.$order->order_number.' wurde fortgesetzt. Die Trageserie beginnt erneut bei Tag 1.',route('orders.show',$order));
        return back()->with('success','Auftrag wurde fortgesetzt und die Serie neu gestartet.');
    }

    public function updateRequirements(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_if($order->status==='completed',422,'Ein abgeschlossener Auftrag kann nicht verändert werden.');

        $data=$request->validate([
            'requirement_text'=>['required','string','max:5000'],
            'effective_mode'=>['required','in:immediately,next_window,next_day,custom'],
            'effective_at'=>['nullable','date','required_if:effective_mode,custom'],
            'additional_compensation'=>['nullable','numeric','min:0'],
        ]);

        $effectiveAt=match($data['effective_mode']){
            'immediately'=>now(),
            'next_window'=>now(),
            'next_day'=>now('Europe/Berlin')->startOfDay()->addDay(),
            'custom'=>\Carbon\Carbon::parse($data['effective_at'],'Europe/Berlin'),
        };

        $before=$order->toArray();
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

        $audit->log('order.requirements.changed',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'order_requirements_changed',
            'Auftragsanforderungen geändert',
            'Für Auftrag #'.$order->order_number.' wurden verbindliche Anforderungen geändert. Wirksam ab '.$effectiveAt->format('d.m.Y H:i').' Uhr.',
            route('orders.show',$order)
        );

        return back()->with('success','Die neuen Anforderungen wurden gespeichert und mitgeteilt.');
    }

    public function release(Order $order, WalletService $wallet, AuditService $audit, NotificationService $notifications)
    {
        $before=$order->toArray();
        $wallet->release($order);
        $audit->log('order.compensation.released',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'compensation_released',
            'Vergütung freigegeben',
            'Die Vergütung für Auftrag #'.$order->order_number.' ist jetzt in deinem Wallet verfügbar.',
            route('wallet.index')
        );
        return back()->with('success','Vergütung wurde freigegeben und der Auftrag abgeschlossen.');
    }
}
