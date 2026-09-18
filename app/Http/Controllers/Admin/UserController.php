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
        $user->load('profile','orders','warnings.issuer','restrictions.issuer','verifications');
        return view('admin.users.show',compact('user'));
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
