<?php
namespace App\Services;

use App\Models\Order;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(private ReliabilityService $reliability) {}

    public function release(Order $order): void
    {
        $completedOrder=DB::transaction(function() use($order){
            $order=Order::with(['user','goodsInspection','goodsReceipt','days.proofs','shipment'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($order->status==='accepted',422,'Vergütung kann erst nach abgeschlossener und angenommener Warenprüfung freigegeben werden.');
            abort_unless($order->goodsInspection && $order->goodsInspection->result==='accepted',422,'Es fehlt eine erfolgreich abgeschlossene Warenprüfung.');
            abort_unless(
                $order->readyForFinalInspection(),
                422,
                'Vergütung darf nur freigegeben werden, wenn alle erforderlichen Nachweise und der vollständige Wareneingang bestätigt sind.'
            );

            $wallet=WalletAccount::where('user_id',$order->user_id)->lockForUpdate()->firstOrCreate(['user_id'=>$order->user_id]);
            $already=$wallet->entries()
                ->where('order_id',$order->id)
                ->where('entry_type','order_released')
                ->exists();

            abort_if($already,422,'Die Vergütung dieses Auftrags wurde bereits freigegeben.');

            $amount=round((float)($order->final_compensation ?? $order->goodsInspection->calculated_compensation ?? $order->compensation_total),2);
            abort_if($amount<0,422,'Ungültiger Vergütungsbetrag.');

            if($amount>0){
                $wallet->entries()->create([
                    'order_id'=>$order->id,
                    'bucket'=>'available',
                    'entry_type'=>'order_released',
                    'amount'=>$amount,
                    'reference'=>$order->order_number,
                    'description'=>'Vergütung nach finaler Warenprüfung freigegeben',
                    'metadata'=>['goods_inspection_id'=>$order->goodsInspection->id],
                ]);
            }

            $order->update([
                'status'=>'completed',
                'final_compensation'=>$amount,
                'completed_at'=>now(),
            ]);

            $order->statusHistory()->create([
                'changed_by'=>auth()->id(),
                'from_status'=>'accepted',
                'to_status'=>'completed',
                'reason'=>'Finale Vergütung '.$amount.' EUR freigegeben',
            ]);

            return $order->fresh(['user','goodsInspection','days.proofs','shipment']);
        });

        $this->reliability->recordCleanCompletion($completedOrder);
    }
}
