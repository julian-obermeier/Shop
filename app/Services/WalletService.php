<?php
namespace App\Services;
use App\Models\Order;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
class WalletService {
    public function release(Order $order): void {
        DB::transaction(function () use ($order) {
            abort_unless(in_array($order->status, ['received','inspection','accepted'], true), 422, 'Vergütung kann in diesem Status nicht freigegeben werden.');
            $wallet = WalletAccount::firstOrCreate(['user_id'=>$order->user_id]);
            $already = $wallet->entries()->where('order_id',$order->id)->where('entry_type','order_released')->exists();
            if ($already) return;
            $amount=(float)$order->compensation_total;
            $wallet->entries()->create(['order_id'=>$order->id,'bucket'=>'pending','entry_type'=>'pending_reversal','amount'=>-$amount,'reference'=>$order->order_number,'description'=>'Vormerkung freigegeben']);
            $wallet->entries()->create(['order_id'=>$order->id,'bucket'=>'available','entry_type'=>'order_released','amount'=>$amount,'reference'=>$order->order_number,'description'=>'Vergütung nach Prüfung freigegeben']);
            $order->update(['status'=>'compensation_released']);
            $order->statusHistory()->create(['changed_by'=>auth()->id(),'from_status'=>'inspection','to_status'=>'compensation_released','reason'=>'Vergütung freigegeben']);
        });
    }
}
