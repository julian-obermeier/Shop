<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index()
    {
        $admins=User::whereIn('role',['superadmin','admin','staff','accounting'])->orderBy('role')->orderBy('last_name')->get();
        return view('admin.admin-users.index',compact('admins'));
    }

    public function store(Request $request, AuditService $audit)
    {
        $roles=$request->user()->role==='superadmin'
            ? ['superadmin','admin','staff','accounting']
            : ['admin','staff','accounting'];

        $data=$request->validate([
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'email'=>['required','email','max:255','unique:users,email'],
            'role'=>['required','in:'.implode(',',$roles)],
            'password'=>['required','string','min:12','confirmed'],
        ]);

        $user=User::create([
            'first_name'=>$data['first_name'],
            'last_name'=>$data['last_name'],
            'email'=>$data['email'],
            'birth_date'=>'1990-01-01',
            'role'=>$data['role'],
            'password'=>$data['password'],
            'status'=>'active',
            'verified_at'=>now(),
        ]);

        $audit->log('admin_user.created',$user,[],$user->toArray());
        return back()->with('success','Administrationskonto wurde angelegt. Beim Login ist 2FA verpflichtend.');
    }

    public function update(Request $request, User $adminUser, AuditService $audit)
    {
        abort_unless($adminUser->isAdmin(),404);
        abort_if($adminUser->id===$request->user()->id,422,'Das eigene Administrationskonto kann hier nicht verändert werden.');
        abort_if($adminUser->role==='superadmin' && $request->user()->role!=='superadmin',403);

        $roles=$request->user()->role==='superadmin'
            ? ['superadmin','admin','staff','accounting']
            : ['admin','staff','accounting'];

        $data=$request->validate([
            'role'=>['required','in:'.implode(',',$roles)],
            'status'=>['required','in:active,suspended'],
        ]);

        $before=$adminUser->toArray();
        $adminUser->update($data);
        $audit->log('admin_user.updated',$adminUser,$before,$adminUser->fresh()->toArray());

        return back()->with('success','Administrationskonto wurde aktualisiert.');
    }
}
