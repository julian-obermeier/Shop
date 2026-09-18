<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query=User::where('role','provider')->withCount('orders');
        if($request->filled('q')) {
            $search=$request->string('q');
            $query->where(fn($q)=>$q->where('first_name','like',"%{$search}%")->orWhere('last_name','like',"%{$search}%")->orWhere('email','like',"%{$search}%"));
        }
        if($request->filled('status')) $query->where('status',$request->status);
        $users=$query->latest()->paginate(30)->withQueryString();
        return view('admin.users.index',compact('users'));
    }

    public function show(User $user)
    {
        $user->load('profile','orders','warnings.issuer','restrictions.issuer');
        return view('admin.users.show',compact('user'));
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
        $audit->log('user.master_data.updated',$user,$before,['user'=>$user->fresh()->toArray(),'profile'=>$user->profile()->first()?->toArray()]);
        return back()->with('success','Stammdaten wurden aktualisiert.');
    }

    public function warning(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate([
            'level'=>['required','in:info,warning,serious'],
            'title'=>['required','string','max:180'],
            'reason'=>['required','string','max:2000'],
            'expires_at'=>['nullable','date'],
        ]);
        $warning=$user->warnings()->create($data+['issued_by'=>$request->user()->id]);
        $audit->log('user.warning.created',$warning,[], $warning->toArray());
        $notifications->send($user,'warning',$data['title'],$data['reason'],route('profile.edit'));
        return back()->with('success','Verwarnung wurde gespeichert.');
    }

    public function restriction(Request $request, User $user, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate([
            'type'=>['required','in:offers,payouts,uploads,account'],
            'reason'=>['required','string','max:1000'],
            'ends_at'=>['nullable','date','after:now'],
        ]);
        $restriction=$user->restrictions()->create([
            'issued_by'=>$request->user()->id,
            'type'=>$data['type'],
            'reason'=>$data['reason'],
            'starts_at'=>now(),
            'ends_at'=>$data['ends_at']??null,
            'active'=>true,
        ]);
        if($data['type']==='account') $user->update(['status'=>'suspended']);
        $audit->log('user.restriction.created',$restriction,[], $restriction->toArray());
        $notifications->send($user,'restriction','Kontoeinschränkung',$data['reason'],route('profile.edit'));
        return back()->with('success','Einschränkung wurde aktiviert.');
    }

    public function removeRestriction(Request $request, User $user, int $restriction, AuditService $audit)
    {
        $item=$user->restrictions()->findOrFail($restriction);
        $before=$item->toArray();
        $item->update(['active'=>false,'ends_at'=>now()]);
        if($item->type==='account' && !$user->restrictions()->current()->where('type','account')->exists()) $user->update(['status'=>'active']);
        $audit->log('user.restriction.removed',$item,$before,$item->fresh()->toArray());
        return back()->with('success','Einschränkung wurde aufgehoben.');
    }
}
