<?php
namespace App\Http\Controllers;

use App\Models\PayoutRequest;
use App\Models\WalletAccount;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function index()
    {
        $user=request()->user()->load('profile');
        $wallet=$user->walletAccount()->with('entries')->firstOrCreate([]);
        $payouts=PayoutRequest::where('user_id',$user->id)->latest()->get();

        return view('wallet.index',compact('wallet','payouts','user'));
    }

    public function updatePayoutDetails(Request $request)
    {
        $user=$request->user();
        $data=$request->validate([
            'bank_iban'=>['nullable','string','max:34'],
            'bank_account_holder'=>['nullable','string','max:255'],
            'paypal_email'=>['nullable','email','max:255'],
            'paypal_name'=>['nullable','string','max:255'],
        ]);

        $profile=$user->profile()->firstOrNew([]);
        $normalized=[
            'bank_iban'=>($data['bank_iban']??null) !== null ? trim((string)$data['bank_iban']) : null,
            'bank_account_holder'=>($data['bank_account_holder']??null) !== null ? trim((string)$data['bank_account_holder']) : null,
            'paypal_email'=>($data['paypal_email']??null) !== null ? trim((string)$data['paypal_email']) : null,
            'paypal_name'=>($data['paypal_name']??null) !== null ? trim((string)$data['paypal_name']) : null,
        ];

        foreach($normalized as $key=>$value){
            if($value==='') $normalized[$key]=null;
        }

        $changed=collect(array_keys($normalized))
            ->contains(fn($key)=>(string)($profile->{$key}??'') !== (string)($normalized[$key]??''));

        if(!$changed){
            return back()->with('success','Auszahlungsdaten sind unverändert. Die bestehende 24-Stunden-Sicherheitsfrist wurde nicht neu gestartet.');
        }

        $fullName=$this->normalizeName($user->first_name.' '.$user->last_name);
        $bankName=$this->normalizeName($normalized['bank_account_holder']??'');
        $paypalName=$this->normalizeName($normalized['paypal_name']??'');
        $mismatch=($bankName!=='' && $bankName!==$fullName) || ($paypalName!=='' && $paypalName!==$fullName);

        $recipientNamesChanged=
            (string)($profile->bank_account_holder??'') !== (string)($normalized['bank_account_holder']??'')
            || (string)($profile->paypal_name??'') !== (string)($normalized['paypal_name']??'');

        $approvedAt=$profile->payout_name_approved_at;
        if($recipientNamesChanged){
            $approvedAt=$mismatch?null:now();
        } elseif(!$mismatch){
            $approvedAt=$approvedAt ?: now();
        }

        $profile->fill($normalized+[
            'payout_details_changed_at'=>now(),
            'payout_name_approved_at'=>$approvedAt,
        ])->save();

        return back()->with('success',$mismatch && !$approvedAt
            ? 'Auszahlungsdaten gespeichert. Wegen des abweichenden Empfängernamens ist zusätzlich eine Adminfreigabe erforderlich.'
            : 'Auszahlungsdaten gespeichert. Für neue Auszahlungen gilt jetzt die 24-Stunden-Sicherheitssperre.');
    }

    public function payout(Request $request, NotificationService $notifications)
    {
        $user=$request->user()->load('profile');

        abort_unless($user->hasVerifiedEmail(),422,'Bitte bestätige vor einer Auszahlung deine E-Mail-Adresse.');
        abort_if(PayoutRequest::where('user_id',$user->id)->whereIn('status',['requested','review','approved','payment_executed','failed'])->exists(),422,'Es kann nur eine offene Auszahlung gleichzeitig bestehen.');

        $data=$request->validate([
            'amount'=>['required','numeric','min:0.01'],
            'method'=>['required','in:bank_transfer,paypal'],
        ]);

        $profile=$user->profile;
        abort_unless($profile,422,'Bitte hinterlege zuerst Auszahlungsdaten.');
        abort_if(!$profile->payout_details_changed_at || $profile->payout_details_changed_at->gt(now()->subHours(24)),422,'Neue oder geänderte Auszahlungsdaten können erst nach 24 Stunden verwendet werden.');

        $fullName=$this->normalizeName($user->first_name.' '.$user->last_name);

        if($data['method']==='bank_transfer'){
            abort_unless($profile->bank_iban && $profile->bank_account_holder,422,'Bitte hinterlege IBAN und Kontoinhaber.');
            $destination=[
                'iban'=>$profile->bank_iban,
                'account_holder'=>$profile->bank_account_holder,
            ];
            $recipientName=$profile->bank_account_holder;
        } else {
            abort_unless($profile->paypal_email && $profile->paypal_name,422,'Bitte hinterlege PayPal-E-Mail und PayPal-Name.');
            $destination=[
                'paypal_email'=>$profile->paypal_email,
                'paypal_name'=>$profile->paypal_name,
            ];
            $recipientName=$profile->paypal_name;
        }

        if($this->normalizeName($recipientName)!==$fullName){
            abort_unless($profile->payout_name_approved_at,422,'Der abweichende Auszahlungsempfänger muss zuerst vom Admin freigegeben werden.');
        }

        $payout=DB::transaction(function() use($user,$data,$destination){
            $wallet=WalletAccount::where('user_id',$user->id)->lockForUpdate()->firstOrFail();
            $amount=round((float)$data['amount'],2);
            $available=$wallet->balance('available');
            abort_if($amount>$available,422,'Nicht genügend verfügbares Guthaben.');

            do{
                $number='P'.now()->format('YmdHis').$user->id.Str::upper(Str::random(6));
            }while(PayoutRequest::where('payout_number',$number)->exists());
            $payout=PayoutRequest::create([
                'payout_number'=>$number,
                'user_id'=>$user->id,
                'amount'=>$amount,
                'status'=>'requested',
                'method'=>$data['method'],
                'destination'=>$destination,
                'processing_date'=>$this->processingDate(),
            ]);

            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'payout_reserved',
                'amount'=>-$amount,
                'reference'=>$number,
                'description'=>'Für Auszahlung reserviert',
                'metadata'=>['payout_request_id'=>$payout->id],
            ]);
            $wallet->entries()->create([
                'bucket'=>'payout_pending',
                'entry_type'=>'payout_requested',
                'amount'=>$amount,
                'reference'=>$number,
                'description'=>'Auszahlung beantragt',
                'metadata'=>['payout_request_id'=>$payout->id],
            ]);

            return $payout;
        });

        $notifications->send(
            $user,
            'payout_requested',
            'Auszahlung beantragt',
            'Deine Auszahlung '.$payout->payout_number.' wurde für Freitag, den '.$payout->processing_date->format('d.m.Y').', vorgemerkt.',
            route('wallet.index')
        );

        return back()->with('success','Auszahlung wurde beantragt und der Betrag reserviert.');
    }

    public function cancelPayout(Request $request, PayoutRequest $payout)
    {
        abort_unless($payout->user_id===$request->user()->id,403);
        abort_unless(in_array($payout->status,['requested','review'],true),422,'Diese Auszahlung kann nicht mehr selbst storniert werden.');

        DB::transaction(function() use($payout){
            $payout=PayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($payout->status,['requested','review'],true),422,'Diese Auszahlung kann nicht mehr selbst storniert werden.');

            $wallet=WalletAccount::where('user_id',$payout->user_id)->lockForUpdate()->firstOrFail();
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
                    'description'=>'Auszahlungsreservierung storniert',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
                $wallet->entries()->create([
                    'bucket'=>'available',
                    'entry_type'=>'payout_restored',
                    'amount'=>$pendingForPayout,
                    'reference'=>$payout->payout_number,
                    'description'=>'Guthaben nach Storno wieder verfügbar',
                    'metadata'=>['payout_request_id'=>$payout->id],
                ]);
            }
            $payout->update(['status'=>'cancelled']);
        });

        return back()->with('success','Auszahlungsantrag wurde storniert und das reservierte Guthaben freigegeben.');
    }

    private function processingDate(): string
    {
        $now=CarbonImmutable::now('Europe/Berlin');
        $monday=$now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $cutoff=$monday->addDays(3)->setTime(23,59,59);
        $thisFriday=$monday->addDays(4)->startOfDay();

        return $now->lessThanOrEqualTo($cutoff)
            ? $thisFriday->toDateString()
            : $thisFriday->addWeek()->toDateString();
    }

    private function normalizeName(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$value)));
    }
}
