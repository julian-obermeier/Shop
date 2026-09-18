<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends Controller
{
    public function index()
    {
        $verifications=IdentityVerification::with('user')->whereIn('status',['requested','review'])->latest()->paginate(30);
        return view('admin.verifications.index',compact('verifications'));
    }

    public function file(IdentityVerification $verification,string $side): StreamedResponse
    {
        abort_unless(in_array($side,['front','back'],true),404);
        $path=$side==='front'?$verification->document_front_path:$verification->document_back_path;
        abort_unless($path,404);
        return Storage::disk('identity')->download($path);
    }

    public function review(Request $request, IdentityVerification $verification, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate(['status'=>['required','in:accepted,rejected'],'admin_comment'=>['nullable','string','max:2000']]);
        $before=$verification->toArray();
        $verification->update([
            'status'=>$data['status'],
            'admin_comment'=>$data['admin_comment']??null,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
        ]);

        if($data['status']==='accepted') $verification->user()->update(['verified_at'=>now()]);

        $audit->log('verification.reviewed',$verification,$before,$verification->fresh()->toArray());
        $notifications->send(
            $verification->user,
            'verification_'.$data['status'],
            $data['status']==='accepted'?'Verifizierung abgeschlossen':'Verifizierung abgelehnt',
            $data['admin_comment'] ?: ($data['status']==='accepted'?'Dein Konto wurde erfolgreich verifiziert.':'Bitte prüfe die Rückmeldung zur Verifizierung.'),
            route('verification.index')
        );

        return back()->with('success','Verifizierung wurde bearbeitet.');
    }
}
