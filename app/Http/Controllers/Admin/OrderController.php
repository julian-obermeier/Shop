<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\WalletService;
use Illuminate\Http\Request;

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
            'precheck','shipment','goodsReceipt','conversation'
        );
        return view('admin.orders.show',compact('order'));
    }

    public function status(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        $allowed=[
            'precheck','precheck_resubmit','approved','active','waiting_shipping',
            'shipping_overdue','shipped','received','inspection','accepted',
            'compensation_released','completed','cancelled','rejected','dispute',
        ];

        $data=$request->validate([
            'status'=>['required','in:'.implode(',',$allowed)],
            'reason'=>['nullable','string','max:1000'],
        ]);

        $before=$order->toArray();
        $from=$order->status;
        $order->update(['status'=>$data['status']]);
        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>$data['status'],
            'reason'=>$data['reason']??null,
        ]);

        $audit->log('order.status.changed',$order,$before,$order->fresh()->toArray());
        $notifications->send(
            $order->user,
            'order_status',
            'Auftragsstatus aktualisiert',
            'Auftrag #'.$order->order_number.' steht jetzt auf '.strtoupper(str_replace('_',' ',$data['status'])).'.',
            route('orders.show',$order)
        );

        return back()->with('success','Status wurde geändert.');
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
        return back()->with('success','Vergütung wurde freigegeben.');
    }
}
