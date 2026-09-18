<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoodsReceiptController extends Controller
{
    public function store(Request $request, Order $order, AuditService $audit, NotificationService $notifications)
    {
        abort_unless(in_array($order->status,['shipped','received'],true),422,'Wareneingang ist in diesem Status nicht möglich.');
        $data=$request->validate(['complete'=>['required','boolean'],'note'=>['nullable','string','max:2000']]);
        $before=$order->toArray();

        DB::transaction(function() use($request,$order,$data){
            $order->shipment?->update(['risk_transferred_at'=>now()]);

            $order->goodsReceipt()->updateOrCreate([],[
                'received_by'=>$request->user()->id,
                'status'=>$data['complete']?'received':'partial',
                'complete'=>$data['complete'],
                'note'=>$data['note']??null,
                'received_at'=>now(),
            ]);
            $from=$order->status;
            $to=$data['complete']?'inspection':'received';
            $order->update(['status'=>$to,'received_at'=>now()]);
            if($data['complete'] && $order->shipment){
                $order->shipment->update([
                    'delivered_at'=>$order->shipment->delivered_at ?: now(),
                    'risk_transferred_at'=>$order->shipment->risk_transferred_at ?: now(),
                ]);
            }
            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>$from,
                'to_status'=>$to,
                'reason'=>$data['note']??'Wareneingang erfasst',
            ]);
        });

        $audit->log('goods_receipt.recorded',$order,$before,$order->fresh()->toArray());
        $notifications->send($order->user,'goods_receipt','Wareneingang erfasst',$data['complete']?'Deine Sendung zu Auftrag #'.$order->order_number.' ist eingegangen und wird geprüft.':'Beim Wareneingang zu Auftrag #'.$order->order_number.' wurde eine Abweichung festgestellt.',route('orders.show',$order));

        return back()->with('success','Wareneingang wurde erfasst.');
    }
}
