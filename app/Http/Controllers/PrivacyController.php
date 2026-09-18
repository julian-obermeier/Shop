<?php
namespace App\Http\Controllers;

use App\Models\PrivacyRequest;
use App\Services\PrivacyService;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function index(PrivacyService $privacy)
    {
        $requests=request()->user()->privacyRequests()->latest()->get();
        $blockers=$privacy->blockingReasons(request()->user());

        return view('privacy.index',compact('requests','blockers'));
    }

    public function export(PrivacyService $privacy)
    {
        $data=$privacy->export(request()->user());
        $filename='wear-and-earn-datenauszug-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            function() use($data){
                echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            },
            $filename,
            ['Content-Type'=>'application/json; charset=UTF-8']
        );
    }

    public function requestDeletion(Request $request)
    {
        $data=$request->validate([
            'reason'=>['nullable','string','max:2000'],
        ]);

        abort_if(
            $request->user()->privacyRequests()
                ->where('type','deletion')
                ->whereIn('status',['requested','review','approved'])
                ->exists(),
            422,
            'Es besteht bereits ein offener Lösch-/Anonymisierungsantrag.'
        );

        PrivacyRequest::create([
            'user_id'=>$request->user()->id,
            'type'=>'deletion',
            'status'=>'requested',
            'reason'=>$data['reason']??null,
        ]);

        return back()->with('success','Dein Lösch-/Anonymisierungsantrag wurde eingereicht.');
    }

    public function cancel(Request $request, PrivacyRequest $privacyRequest)
    {
        abort_unless($privacyRequest->user_id===$request->user()->id,403);
        abort_unless($privacyRequest->status==='requested',422,'Dieser Antrag kann nicht mehr selbst storniert werden.');

        $privacyRequest->update(['status'=>'cancelled']);

        return back()->with('success','Der Datenschutzantrag wurde storniert.');
    }
}
