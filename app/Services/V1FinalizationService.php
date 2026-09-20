<?php
namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

class V1FinalizationService
{
    public function finalize(Order $order, User $admin, float $amount, string $decision, ?string $reason=null): Order
    {
        return DB::transaction(function() use($order,$admin,$amount,$decision,$reason){
            $order=Order::with('user')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->isTerminal(),422,'Dieser Auftrag ist bereits endgültig beendet.');

            $amount=round(max(0,$amount),2);
            abort_if($amount>(float)$order->compensation_total,422,'Der freizugebende Betrag darf den Auftragswert nicht überschreiten.');

            $wallet=WalletAccount::where('user_id',$order->user_id)->lockForUpdate()->firstOrCreate(['user_id'=>$order->user_id]);
            $pending=(float)$wallet->entries()->where('order_id',$order->id)->where('bucket','pending')->sum('amount');

            if($pending!=0.0){
                $wallet->entries()->create([
                    'order_id'=>$order->id,
                    'bucket'=>'pending',
                    'entry_type'=>'order_reservation_closed',
                    'amount'=>-$pending,
                    'reference'=>$order->order_number,
                    'description'=>'Vorgemerkten Auftragswert abgeschlossen',
                    'metadata'=>['decision'=>$decision],
                ]);
            }

            if($amount>0){
                $wallet->entries()->create([
                    'order_id'=>$order->id,
                    'bucket'=>'available',
                    'entry_type'=>'order_released',
                    'amount'=>$amount,
                    'reference'=>$order->order_number,
                    'description'=>'Finale Vergütung freigegeben',
                    'metadata'=>['decision'=>$decision,'reason'=>$reason],
                ]);
            }

            $from=$order->status;
            $rejected=$decision==='rejected';

            $order->update([
                'status'=>$rejected?'rejected':'completed',
                'phase'=>$rejected?'archive':'payout',
                'final_compensation'=>$amount,
                'completed_at'=>now(),
                'archived_at'=>$rejected?now():null,
            ]);

            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>$from,
                'to_status'=>$rejected?'rejected':'completed',
                'reason'=>($decision==='partial'?'Teilweise akzeptiert':'Abschluss: '.$decision).' · '.number_format($amount,2,'.','').' EUR'.($reason?' · '.$reason:''),
            ]);

            return $order->fresh();
        });
    }

    public function archivePaidOrdersForUser(int $userId): void
    {
        $wallet=WalletAccount::where('user_id',$userId)->first();
        if(!$wallet || $wallet->balance('available')>0.0001 || $wallet->balance('payout_pending')>0.0001) return;

        Order::where('user_id',$userId)
            ->where('status','completed')
            ->whereNull('archived_at')
            ->update(['status'=>'archived','phase'=>'archive','archived_at'=>now()]);
    }
}
