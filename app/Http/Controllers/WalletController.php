<?php
namespace App\Http\Controllers;
use App\Models\PayoutRequest;
use App\Models\WalletAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class WalletController extends Controller {
    public function index(){ $wallet=request()->user()->walletAccount()->with('entries')->firstOrCreate([]); $payouts=PayoutRequest::where('user_id',request()->user()->id)->latest()->get(); return view('wallet.index',compact('wallet','payouts')); }
    public function payout(Request $request){
        $data=$request->validate(['amount'=>['required','numeric','min:10'],'iban'=>['required','string','max:34']]);
        DB::transaction(function() use($request,$data){
            $wallet=WalletAccount::where('user_id',$request->user()->id)->lockForUpdate()->firstOrFail();
            $available=(float)$wallet->entries()->where('bucket','available')->sum('amount'); $amount=(float)$data['amount'];
            abort_if($amount>$available,422,'Nicht genügend verfügbares Guthaben.');
            $number='P'.now()->format('YmdHis').$request->user()->id;
            $payout=PayoutRequest::create(['payout_number'=>$number,'user_id'=>$request->user()->id,'amount'=>$amount,'status'=>'requested','method'=>'bank_transfer','destination'=>['iban'=>$data['iban']]]);
            $wallet->entries()->create(['bucket'=>'available','entry_type'=>'payout_reserved','amount'=>-$amount,'reference'=>$number,'description'=>'Für Auszahlung reserviert','metadata'=>['payout_request_id'=>$payout->id]]);
            $wallet->entries()->create(['bucket'=>'payout_pending','entry_type'=>'payout_requested','amount'=>$amount,'reference'=>$number,'description'=>'Auszahlung beantragt','metadata'=>['payout_request_id'=>$payout->id]]);
        });
        return back()->with('success','Auszahlung wurde beantragt und der Betrag reserviert.');
    }
}
