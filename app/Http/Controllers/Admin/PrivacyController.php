<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use App\Services\AuditService;
use App\Services\PrivacyService;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function index()
    {
        $requests=PrivacyRequest::with('user','reviewer')->latest()->paginate(30);
        return view('admin.privacy.index',compact('requests'));
    }

    public function show(PrivacyRequest $privacyRequest, PrivacyService $privacy)
    {
        $privacyRequest->load('user','reviewer');
        $blockers=$privacy->blockingReasons($privacyRequest->user);

        return view('admin.privacy.show',compact('privacyRequest','blockers'));
    }

    public function review(Request $request, PrivacyRequest $privacyRequest, AuditService $audit)
    {
        $data=$request->validate([
            'status'=>['required','in:review,approved,rejected'],
            'admin_note'=>['nullable','string','max:3000'],
        ]);

        abort_if(in_array($privacyRequest->status,['completed','cancelled'],true),422,'Dieser Antrag ist bereits abgeschlossen.');

        $before=$privacyRequest->toArray();
        $privacyRequest->update([
            'status'=>$data['status'],
            'admin_note'=>$data['admin_note']??null,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
        ]);

        $audit->log('privacy_request.reviewed',$privacyRequest,$before,$privacyRequest->fresh()->toArray());

        return back()->with('success','Datenschutzantrag wurde aktualisiert.');
    }

    public function anonymize(Request $request, PrivacyRequest $privacyRequest, PrivacyService $privacy, AuditService $audit)
    {
        $request->validate(['confirm'=>['accepted']]);
        abort_unless($privacyRequest->type==='deletion' && $privacyRequest->status==='approved',422,'Der Antrag muss zuerst freigegeben sein.');

        $before=$privacyRequest->toArray();
        $user=$privacyRequest->user;

        $privacy->anonymize($user);

        $privacyRequest->update([
            'status'=>'completed',
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>$privacyRequest->reviewed_at ?: now(),
            'completed_at'=>now(),
        ]);

        $audit->log('privacy_request.completed',$privacyRequest,$before,$privacyRequest->fresh()->toArray());

        return redirect()->route('admin.privacy.index')->with('success','Das Konto wurde anonymisiert. Abrechnungsrelevante Datensätze bleiben pseudonymisiert erhalten.');
    }
}
