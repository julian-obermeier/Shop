<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PayoutRequest;
use App\Models\WalletAccount;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CompleteExecutedPayouts extends Command
{
    protected $signature='payouts:complete-executed';
    protected $description='Complete payouts 24 hours after the admin confirmed the external payment';

    public function handle(NotificationService $notifications): int
    {
        PayoutRequest::query()
            ->where('status','payment_executed')
            ->whereNotNull('payment_executed_at')
            ->where('payment_executed_at','<=',now()->subHours(24))
            ->orderBy('id')
            ->chunkById(100,function($rows) use($notifications){
                foreach($rows as $row){
                    DB::transaction(function() use($row,$notifications){
                        $payout=PayoutRequest::whereKey($row->id)->lockForUpdate()->first();
                        if(!$payout || $payout->status!=='payment_executed' || !$payout->payment_executed_at || $payout->payment_executed_at->gt(now()->subHours(24))) return;

                        $wallet=WalletAccount::where('user_id',$payout->user_id)->lockForUpdate()->firstOrFail();
                        $amount=(float)$payout->amount;

                        $paidForPayout=(float)$wallet->entries()
                            ->where('reference',$payout->payout_number)
                            ->where('bucket','paid')
                            ->sum('amount');

                        if($paidForPayout<=0){
                            $pendingForPayout=(float)$wallet->entries()
                                ->where('reference',$payout->payout_number)
                                ->where('bucket','payout_pending')
                                ->sum('amount');

                            if($pendingForPayout>0){
                                $wallet->entries()->create([
                                    'bucket'=>'payout_pending',
                                    'entry_type'=>'payout_completed',
                                    'amount'=>-$pendingForPayout,
                                    'reference'=>$payout->payout_number,
                                    'description'=>'Auszahlung nach 24 Stunden abgeschlossen',
                                    'metadata'=>['payout_request_id'=>$payout->id],
                                ]);
                            }

                            $wallet->entries()->create([
                                'bucket'=>'paid',
                                'entry_type'=>'payout_paid',
                                'amount'=>$amount,
                                'reference'=>$payout->payout_number,
                                'description'=>'Extern ausgezahlt',
                                'metadata'=>['payout_request_id'=>$payout->id],
                            ]);
                        }

                        $before=$payout->toArray();
                        $payout->update([
                            'status'=>'completed',
                            'completed_at'=>now(),
                            'paid_at'=>now(),
                        ]);

                        AuditLog::create([
                            'user_id'=>null,
                            'action'=>'payout.auto_completed',
                            'auditable_type'=>PayoutRequest::class,
                            'auditable_id'=>$payout->id,
                            'before'=>$before,
                            'after'=>$payout->fresh()->toArray(),
                            'ip_address'=>null,
                            'user_agent'=>'scheduler',
                        ]);

                        $notifications->send(
                            $payout->user,
                            'payout_completed',
                            'Auszahlung abgeschlossen',
                            'Deine Auszahlung '.$payout->payout_number.' wurde abgeschlossen.',
                            route('wallet.index')
                        );
                    });
                }
            });

        return self::SUCCESS;
    }
}
