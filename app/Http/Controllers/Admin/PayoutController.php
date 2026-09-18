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
        $payouts=PayoutRequest::with('user')->latest()->paginate(30);
        return view('admin.payouts.index',compact('payouts'));
    }

    public function update(Request $request, PayoutRequest $payout, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate([
            'status'=>['required','in:review,approved,paid,rejected,failed,cancelled'],
            'admin_note'=>['nullable','string','max:2000'],
        ]);

        DB::transaction(function() use($payout,$data,$audit,$notifications) {
            $payout=PayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($payout->status,['paid','rejected','failed','cancelled'],true),422,'Diese Auszahlung ist bereits abgeschlossen.');
            $before=$payout->toArray();
            $wallet=WalletAccount::where('user_id',$payout->user_id)->lockForUpdate()->firstOrFail();
            $amount=(float)$payout->amount;

            if($data['status']==='paid') {
                abort_if($wallet->entries()->where('reference',$payout->payout_number)->where('entry_type','payout_paid')->exists(),422,'Diese Auszahlung wurde bereits verbucht.');
                $wallet->entries()->create(['bucket'=>'payout_pending','entry_type'=>'payout_completed','amount'=>-$amount,'reference'=>$payout->payout_number,'description'=>'Auszahlung abgeschlossen','metadata'=>['payout_request_id'=>$payout->id]]);
                $wallet->entries()->create(['bucket'=>'paid','entry_type'=>'payout_paid','amount'=>$amount,'reference'=>$payout->payout_number,'description'=>'Ausgezahlt','metadata'=>['payout_request_id'=>$payout->id]]);
            }

            if(in_array($data['status'],['rejected','failed','cancelled'],true)) {
                $alreadyRestored=$wallet->entries()->where('reference',$payout->payout_number)->where('entry_type','payout_restored')->exists();
                if(!$alreadyRestored){
                    $wallet->entries()->create(['bucket'=>'payout_pending','entry_type'=>'payout_pending_reversal','amount'=>-$amount,'reference'=>$payout->payout_number,'description'=>'Auszahlungsreservierung aufgehoben','metadata'=>['payout_request_id'=>$payout->id]]);
                    $wallet->entries()->create(['bucket'=>'available','entry_type'=>'payout_restored','amount'=>$amount,'reference'=>$payout->payout_number,'description'=>'Guthaben wieder freigegeben','metadata'=>['payout_request_id'=>$payout->id]]);
                }
            }

            $payout->update([
                'status'=>$data['status'],
                'admin_note'=>$data['admin_note']??null,
                'paid_at'=>$data['status']==='paid'?now():$payout->paid_at,
            ]);

            $audit->log('payout.status.changed',$payout,$before,$payout->fresh()->toArray());
            $notifications->send(
                $payout->user,
                'payout_'.$data['status'],
                'Auszahlung '.strtoupper($data['status']),
                'Dein Auszahlungsantrag '.$payout->payout_number.' wurde aktualisiert.',
                route('wallet.index')
            );
        });

        return back()->with('success','Auszahlungsstatus wurde aktualisiert.');
    }
}
