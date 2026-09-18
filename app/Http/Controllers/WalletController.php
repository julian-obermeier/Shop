<?php
namespace App\Http\Controllers;

use App\Models\PayoutRequest;
use App\Models\Setting;
use App\Models\WalletAccount;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function index()
    {
        $wallet=request()->user()->walletAccount()->with('entries')->firstOrCreate([]);
        $payouts=PayoutRequest::where('user_id',request()->user()->id)->latest()->get();
        $minimum=(float)Setting::valueOf('minimum_payout',10);
        return view('wallet.index',compact('wallet','payouts','minimum'));
    }

    public function payout(Request $request, NotificationService $notifications)
    {
        abort_if($request->user()->hasRestriction('payouts'),422,'Auszahlungen sind für dieses Konto derzeit gesperrt.');
        abort_unless($request->user()->verified_at,422,'Für Auszahlungen ist eine abgeschlossene Identitätsprüfung erforderlich.');
        abort_unless($request->user()->hasVerifiedEmail(),422,'Bitte bestätige vor einer Auszahlung deine E-Mail-Adresse.');

        $minimum=(float)Setting::valueOf('minimum_payout',10);
        $data=$request->validate([
            'amount'=>['required','numeric','min:'.$minimum],
            'iban'=>['required','string','max:34'],
        ]);

        $payout=DB::transaction(function() use($request,$data){
            $wallet=WalletAccount::where('user_id',$request->user()->id)->lockForUpdate()->firstOrFail();
            $available=(float)$wallet->entries()->where('bucket','available')->sum('amount');
            $amount=(float)$data['amount'];
            abort_if($amount>$available,422,'Nicht genügend verfügbares Guthaben.');

            $number='P'.now()->format('YmdHis').$request->user()->id;
            $payout=PayoutRequest::create([
                'payout_number'=>$number,
                'user_id'=>$request->user()->id,
                'amount'=>$amount,
                'status'=>'requested',
                'method'=>'bank_transfer',
                'destination'=>['iban'=>$data['iban']],
            ]);

            $wallet->entries()->create([
                'bucket'=>'available','entry_type'=>'payout_reserved','amount'=>-$amount,
                'reference'=>$number,'description'=>'Für Auszahlung reserviert',
                'metadata'=>['payout_request_id'=>$payout->id],
            ]);
            $wallet->entries()->create([
                'bucket'=>'payout_pending','entry_type'=>'payout_requested','amount'=>$amount,
                'reference'=>$number,'description'=>'Auszahlung beantragt',
                'metadata'=>['payout_request_id'=>$payout->id],
            ]);

            return $payout;
        });

        $notifications->send(
            $request->user(),
            'payout_requested',
            'Auszahlung beantragt',
            'Deine Auszahlung '.$payout->payout_number.' wurde zur Prüfung eingereicht.',
            route('wallet.index')
        );

        return back()->with('success','Auszahlung wurde beantragt und der Betrag reserviert.');
    }
}
