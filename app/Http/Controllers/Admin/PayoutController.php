<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Models\WalletAccount;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayoutController extends Controller
{
    public function index()
    {
        $payouts=PayoutRequest::with('user')->orderByRaw("FIELD(status,'requested','review','approved','failed','payment_executed','completed','rejected','cancelled')")->latest()->paginate(30);
        return view('admin.payouts.index',compact('payouts'));
    }

    public function update(Request $request, PayoutRequest $payout, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate([
            'status'=>['required','in:requested,review,approved,failed,payment_executed,completed,rejected,cancelled'],
            'admin_note'=>['nullable','string','max:2000'],
            'rejection_reason'=>['nullable','string','max:2000','required_if:status,rejected'],
        ]);

        DB::transaction(function() use($payout,$data,$audit,$notifications) {
            $payout=PayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            $before=$payout->toArray();
            $wallet=WalletAccount::where('user_id',$payout->user_id)->lockForUpdate()->firstOrFail();
            $amount=(float)$payout->amount;
            $wasTerminal=in_array($payout->status,['completed','rejected','cancelled'],true);
            $isPaid=$wallet->entries()
                ->where('reference',$payout->payout_number)
                ->where('bucket','paid')
                ->sum('amount') > 0;
            $pendingForPayout=(float)$wallet->entries()
                ->where('reference',$payout->payout_number)
                ->where('bucket','payout_pending')
                ->sum('amount');

            $targetOpen=in_array($data['status'],['requested','review','approved','failed','payment_executed'],true);
            if($targetOpen && !$isPaid && $pendingForPayout<=0){
                $wallet->entries()->create([
                    'bucket'=>'available',
                    'entry_type'=>'payout_rereserved',
                    'amount'=>-$amount,
                    'reference'=>$payout->payout_number,
                    'description'=>'Auszahlung nach Wiederöffnung erneut reserviert',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
                $wallet->entries()->create([
                    'bucket'=>'payout_pending',
                    'entry_type'=>'payout_pending_rereserved',
                    'amount'=>$amount,
                    'reference'=>$payout->payout_number,
                    'description'=>'Auszahlung nach Wiederöffnung erneut reserviert',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
                $pendingForPayout=$amount;
            }

            if($data['status']==='completed' && !$isPaid){
                $wallet->entries()->create([
                    'bucket'=>'payout_pending','entry_type'=>'payout_completed','amount'=>-$pendingForPayout,
                    'reference'=>$payout->payout_number,'description'=>'Auszahlung abgeschlossen',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
                $wallet->entries()->create([
                    'bucket'=>'paid','entry_type'=>'payout_paid','amount'=>$amount,
                    'reference'=>$payout->payout_number,'description'=>'Extern ausgezahlt',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
            }

            if(in_array($data['status'],['rejected','cancelled'],true) && !$isPaid){
                $pendingForPayout=(float)$wallet->entries()
                    ->where('reference',$payout->payout_number)
                    ->where('bucket','payout_pending')
                    ->sum('amount');

                if($pendingForPayout>0){
                    $wallet->entries()->create([
                        'bucket'=>'payout_pending',
                        'entry_type'=>'payout_pending_reversal',
                        'amount'=>-$pendingForPayout,
                        'reference'=>$payout->payout_number,
                        'description'=>'Auszahlungsreservierung aufgehoben',
                        'metadata'=>['payout_request_id'=>$payout->id],
                    ]);
                    $wallet->entries()->create([
                        'bucket'=>'available',
                        'entry_type'=>'payout_restored',
                        'amount'=>$pendingForPayout,
                        'reference'=>$payout->payout_number,
                        'description'=>'Guthaben wieder freigegeben',
                        'metadata'=>['payout_request_id'=>$payout->id],
                    ]);
                }
            }

            $updates=[
                'status'=>$data['status'],
                'admin_note'=>$data['admin_note']??null,
                'rejection_reason'=>$data['rejection_reason']??null,
            ];

            if($data['status']==='approved') $updates['approved_at']=now();
            if($data['status']==='payment_executed') $updates['payment_executed_at']=now();
            if($data['status']==='completed') {
                $updates['completed_at']=now();
                $updates['paid_at']=now();
            }
            if($wasTerminal && $data['status']!==$payout->status) $updates['reopened_at']=now();

            $payout->update($updates);

            $audit->log('payout.status.changed',$payout,$before,$payout->fresh()->toArray());
            $notifications->send(
                $payout->user,
                'payout_'.$data['status'],
                'Auszahlung aktualisiert',
                $data['status']==='rejected'
                    ? 'Auszahlung '.$payout->payout_number.' wurde abgelehnt: '.$data['rejection_reason']
                    : 'Dein Auszahlungsantrag '.$payout->payout_number.' steht jetzt auf '.strtoupper(str_replace('_',' ',$data['status'])).'.',
                route('wallet.index')
            );
        });

        return back()->with('success','Auszahlungsstatus wurde aktualisiert.');
    }
}
