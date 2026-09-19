<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Models\UserRestriction;
use App\Models\WalletAccount;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PrivacyService;
use App\Services\ReliabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query=User::where('role','provider')->withCount('orders');

        if($request->filled('q')){
            $search=$request->string('q');
            $query->where(fn($q)=>$q
                ->where('first_name','like',"%{$search}%")
                ->orWhere('last_name','like',"%{$search}%")
                ->orWhere('email','like',"%{$search}%"));
        }

        if($request->filled('status')) $query->where('status',$request->status);

        $users=$query->latest()->paginate(30)->withQueryString();

        return view('admin.users.index',compact('users'));
    }

    public function show(User $user)
    {
        abort_unless($user->role==='provider',404);

        $user->load([
            'profile',
            'orders'=>fn($q)=>$q->latest(),
            'warnings.issuer',
            'restrictions.issuer',
            'reliabilityEvents'=>fn($q)=>$q->with('order')->latest('occurred_at'),
            'walletAccount.entries',
        ]);

        $offers=Offer::orderBy('title')->get(['id','title']);

        return view('admin.users.show',compact('user','offers'));
    }

    public function approvePayoutName(Request $request, User $user, AuditService $audit)
    {
        abort_unless($user->role==='provider',404);

        $profile=$user->profile()->firstOrFail();
        $before=$profile->toArray();
        $profile->update(['payout_name_approved_at'=>now()]);

        $audit->log('user.payout_recipient.approved',$profile,$before,$profile->fresh()->toArray());

        return back()->with('success','Abweichender Auszahlungsempfänger wurde freigegeben.');
    }

    public function updateMasterData(Request $request, User $user, AuditService $audit)
    {
        abort_unless($user->role==='provider',404);

        $data=$request->validate([
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'birth_date'=>['required','date','before_or_equal:'.now()->subYears(18)->toDateString()],
            'street'=>['nullable','string','max:180'],
            'postal_code'=>['nullable','string','max:20'],
            'city'=>['nullable','string','max:120'],
            'country_code'=>['required','string','size:2'],
        ]);

        $before=['user'=>$user->toArray(),'profile'=>$user->profile?->toArray()];

        $user->update([
            'first_name'=>$data['first_name'],
            'last_name'=>$data['last_name'],
            'birth_date'=>$data['birth_date'],
        ]);

        $user->profile()->updateOrCreate([],[
            'street'=>$data['street']??null,
            'postal_code'=>$data['postal_code']??null,
            'city'=>$data['city']??null,
            'country_code'=>strtoupper($data['country_code']),
        ]);

        $audit->log(
            'user.master_data.updated',
            $user,
            $before,
            ['user'=>$user->fresh()->toArray(),'profile'=>$user->profile()->first()?->toArray()]
        );

        return back()->with('success','Stammdaten wurden aktualisiert.');
    }

    public function walletOverride(Request $request, User $user, AuditService $audit)
    {
        abort_unless($user->role==='provider',404);

        $data=$request->validate([
            'available_balance'=>['required','numeric','min:0','max:99999999.99'],
            'reason'=>['required','string','max:1000'],
        ]);

        DB::transaction(function() use($request,$user,$data,$audit){
            $wallet=WalletAccount::where('user_id',$user->id)->lockForUpdate()->firstOrCreate(['user_id'=>$user->id]);
            $old=$wallet->balance('available');
            $new=round((float)$data['available_balance'],2);

            $before=[
                'available_balance'=>$old,
                'override'=>$wallet->available_balance_override,
                'override_at'=>$wallet->available_balance_override_at?->toIso8601String(),
            ];

            $at=now();
            $wallet->update([
                'available_balance_override'=>$new,
                'available_balance_override_at'=>$at,
            ]);

            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'wallet_override',
                'amount'=>0,
                'reference'=>'OVERRIDE-'.$at->format('YmdHis'),
                'description'=>'Admin-Override des verfügbaren Wallet-Kontostands',
                'metadata'=>[
                    'old_balance'=>$old,
                    'new_balance'=>$new,
                    'reason'=>$data['reason'],
                    'admin_user_id'=>$request->user()->id,
                ],
            ]);

            $audit->log('wallet.override',$wallet,$before,[
                'available_balance'=>$new,
                'reason'=>$data['reason'],
            ]);
        });

        return back()->with('success','Der verfügbare Wallet-Kontostand wurde direkt überschrieben und im Audit protokolliert.');
    }

    public function warning(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($user->role==='provider',404);

        $data=$request->validate([
            'level'=>['required','in:info,warning,serious'],
            'title'=>['required','string','max:180'],
            'reason'=>['required','string','max:2000'],
            'expires_at'=>['nullable','date'],
        ]);

        $warning=$user->warnings()->create($data+['issued_by'=>$request->user()->id]);
        $audit->log('user.warning.created',$warning,[],$warning->toArray());

        $notifications->send($user,'warning',$data['title'],$data['reason'],route('profile.edit'));

        return back()->with('success','Verwarnung wurde gespeichert.');
    }

    public function restriction(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($user->role==='provider',404);

        $data=$request->validate([
            'reason'=>['required','string','max:1000'],
            'max_active_orders'=>['nullable','integer','min:0','max:5'],
            'blocked_offer_ids'=>['nullable','array'],
            'blocked_offer_ids.*'=>['integer','exists:offers,id'],
        ]);

        $restriction=$user->restrictions()->create([
            'issued_by'=>$request->user()->id,
            'type'=>'reliability',
            'reason'=>$data['reason'],
            'starts_at'=>now(),
            'active'=>true,
            'required_successes'=>5,
            'successful_count'=>0,
            'max_active_orders'=>$data['max_active_orders']??null,
            'blocked_offer_ids'=>$data['blocked_offer_ids']??null,
        ]);

        $audit->log('user.restriction.created',$restriction,[],$restriction->toArray());

        $notifications->send(
            $user,
            'restriction',
            'Kontoeinschränkung',
            $data['reason'].' Bewährung: 0 von 5 fehlerfreien Aufträgen.',
            route('profile.edit')
        );

        return back()->with('success','Einschränkung wurde aktiviert.');
    }

    public function removeRestriction(
        Request $request,
        User $user,
        int $restriction,
        AuditService $audit,
        ReliabilityService $reliability
    ){
        abort_unless($user->role==='provider',404);

        $item=$user->restrictions()->findOrFail($restriction);
        $before=$item->toArray();

        if($item->type==='reliability'){
            $reliability->liftRestriction($item,$request->user());
        } else {
            $item->update([
                'active'=>false,
                'ends_at'=>now(),
            ]);
        }

        $audit->log('user.restriction.removed',$item,$before,$item->fresh()->toArray());

        return back()->with('success','Einschränkung wurde aufgehoben.');
    }

    public function deactivate(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($user->role==='provider',404);
        abort_if($user->status!=='active',422,'Dieses Konto ist bereits deaktiviert.');

        $data=$request->validate([
            'reason'=>['required','string','max:2000'],
            'orders'=>['nullable','array'],
            'orders.*.action'=>['required','in:continue,pause,cancel'],
            'orders.*.reason'=>['nullable','string','max:1000'],
            'orders.*.compensation_amount'=>['nullable','numeric','min:0'],
        ]);

        $before=$user->toArray();

        DB::transaction(function() use($request,$user,$data){
            $locked=User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $orders=Order::where('user_id',$locked->id)
                ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
                ->lockForUpdate()
                ->get();

            foreach($orders as $order){
                $decision=$data['orders'][$order->id]??null;
                abort_unless($decision,422,'Für Auftrag #'.$order->order_number.' fehlt die Entscheidung bei Kontodeaktivierung.');

                $action=$decision['action'];
                $reason=trim((string)($decision['reason']??'')) ?: $data['reason'];
                $from=$order->status;

                if($action==='continue'){
                    abort_unless(
                        in_array($from,['shipped','received','inspection','accepted'],true),
                        422,
                        'Auftrag #'.$order->order_number.' benötigt noch eine Interaktion der Anbieterin und darf deshalb bei deaktiviertem Konto nicht weiterlaufen.'
                    );

                    $order->statusHistory()->create([
                        'changed_by'=>$request->user()->id,
                        'from_status'=>$from,
                        'to_status'=>$from,
                        'reason'=>'Kontodeaktivierung: Auftrag darf ohne weitere Anbieterinnen-Interaktion weiterlaufen. '.$reason,
                    ]);

                    continue;
                }

                if($action==='pause'){
                    abort_if(
                        in_array($from,['requested','awaiting_date_confirmation'],true),
                        422,
                        'Noch nicht bestätigte Anfrage #'.$order->order_number.' kann bei Kontodeaktivierung nicht pausiert werden; sie muss abgebrochen werden.'
                    );

                    if($from==='active'){
                        $order->days()
                            ->where('series_number',$order->series_number)
                            ->update(['counts_toward_series'=>false]);
                    }

                    $order->update([
                        'status'=>'paused',
                        'paused_from_status'=>$from,
                        'paused_at'=>now(),
                    ]);

                    $order->statusHistory()->create([
                        'changed_by'=>$request->user()->id,
                        'from_status'=>$from,
                        'to_status'=>'paused',
                        'reason'=>'Kontodeaktivierung: Auftrag pausiert. '.$reason,
                    ]);

                    continue;
                }

                $amount=round((float)($decision['compensation_amount']??0),2);
                abort_if($amount>(float)$order->compensation_total,422,'Die Abbruchvergütung für Auftrag #'.$order->order_number.' ist höher als die vereinbarte Gesamtvergütung.');

                $order->update([
                    'status'=>'cancelled',
                    'final_compensation'=>$amount,
                    'completed_at'=>now(),
                ]);

                $order->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>$from,
                    'to_status'=>'cancelled',
                    'reason'=>'Kontodeaktivierung: Auftrag abgebrochen. '.$reason,
                ]);

                if($amount>0){
                    $wallet=WalletAccount::where('user_id',$locked->id)->lockForUpdate()->firstOrCreate(['user_id'=>$locked->id]);
                    $exists=$wallet->entries()
                        ->where('order_id',$order->id)
                        ->where('entry_type','account_deactivation_compensation')
                        ->exists();

                    if(!$exists){
                        $wallet->entries()->create([
                            'order_id'=>$order->id,
                            'bucket'=>'available',
                            'entry_type'=>'account_deactivation_compensation',
                            'amount'=>$amount,
                            'reference'=>$order->order_number,
                            'description'=>'Vergütung nach Abbruch bei Kontodeaktivierung',
                            'metadata'=>['reason'=>$reason],
                        ]);
                    }
                }
            }

            $locked->update([
                'status'=>'inactive',
                'deactivated_at'=>now(),
                'deactivation_reason'=>$data['reason'],
            ]);
        });

        $audit->log('user.account.deactivated',$user,$before,$user->fresh()->toArray());

        $notifications->send(
            $user,
            'account_deactivated',
            'Konto deaktiviert',
            'Dein Konto wurde deaktiviert. Grund: '.$data['reason'],
            null
        );

        return back()->with('success','Konto wurde deaktiviert; alle offenen Aufträge wurden gemäß den gewählten Entscheidungen verarbeitet.');
    }

    public function deleteAccount(Request $request, User $user, PrivacyService $privacy, AuditService $audit)
    {
        abort_unless($user->role==='provider',404);
        abort_if($user->status==='deleted',422,'Dieses Konto wurde bereits gelöscht/anonymisiert.');

        $data=$request->validate([
            'confirm'=>['accepted'],
            'reason'=>['required','string','max:2000'],
        ]);

        $blockers=$privacy->blockingReasons($user);
        abort_if($blockers!==[],422,'Kontolöschung derzeit nicht möglich: '.implode('; ',$blockers));

        $before=[
            'user'=>$user->toArray(),
            'reason'=>$data['reason'],
        ];

        $privacy->anonymize($user);

        $audit->log('user.account.deleted',$user,$before,[
            'user'=>$user->fresh()->toArray(),
            'reason'=>$data['reason'],
            'evidence_retained'=>true,
        ]);

        return redirect()->route('admin.users.index')
            ->with('success','Konto wurde gelöscht/anonymisiert. Dauerhaft aufzubewahrende Auftragsnachweise und abgeschlossene Transaktionsdaten bleiben erhalten.');
    }

    public function reactivate(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($user->role==='provider',404);
        abort_if($user->status==='active',422,'Dieses Konto ist bereits aktiv.');
        abort_if($user->status==='deleted',422,'Ein gelöschtes/anonymisiertes Konto kann nicht reaktiviert werden.');

        $before=$user->toArray();

        $user->update([
            'status'=>'active',
            'deactivated_at'=>null,
            'deactivation_reason'=>null,
        ]);

        $audit->log('user.account.reactivated',$user,$before,$user->fresh()->toArray());

        $notifications->send(
            $user,
            'account_reactivated',
            'Konto wieder aktiviert',
            'Dein Konto wurde durch den Admin wieder aktiviert. Pausierte Aufträge bleiben bis zu ihrer separaten Fortsetzung pausiert.',
            route('dashboard')
        );

        return back()->with('success','Konto wurde wieder aktiviert. Pausierte Aufträge müssen separat fortgesetzt werden.');
    }
}
