<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProofSubmission;
use App\Services\NotificationService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProofController extends Controller
{
    public function index()
    {
        $proofs=ProofSubmission::with('orderDay.order.user')
            ->where('review_status','pending')
            ->latest()->paginate(40);

        return view('admin.proofs.index',compact('proofs'));
    }

    public function file(ProofSubmission $proof): StreamedResponse
    {
        abort_unless(Storage::disk('proofs')->exists($proof->storage_path),404);
        return Storage::disk('proofs')->download($proof->storage_path,$proof->original_name);
    }

    public function review(
        Request $request,
        ProofSubmission $proof,
        NotificationService $notifications,
        V1OrderWorkflowService $workflow
    ) {
        abort_unless($proof->review_status==='pending',422,'Dieser Nachweis wurde bereits bewertet.');
        abort_if($proof->orderDay->order->isTerminal(),422,'Ein endgültig beendeter Auftrag kann nicht nachträglich verändert werden.');

        $data=$request->validate([
            'review_status'=>['required','in:accepted,rejected'],
            'review_comment'=>['nullable','string','max:1000','required_if:review_status,rejected'],
            'rejection_kind'=>['nullable','in:blurred,dark,framing,incomplete,wrong_subject,timing,other','required_if:review_status,rejected'],
            'resubmit_due_at'=>['nullable','date'],
            'retake_can_cure_violation'=>['nullable','boolean'],
        ]);

        if($data['review_status']==='accepted'){
            $proof->update([
                'review_status'=>'accepted',
                'review_comment'=>$data['review_comment']??null,
                'rejection_kind'=>null,
                'resubmit_due_at'=>null,
                'reviewed_by'=>$request->user()->id,
                'reviewed_at'=>now(),
            ]);
            $this->refreshDayStatus($proof);
            return back()->with('success','Nachweis wurde akzeptiert.');
        }

        $due=!empty($data['resubmit_due_at'])
            ? \Carbon\CarbonImmutable::parse($data['resubmit_due_at'],'Europe/Berlin')
            : now('Europe/Berlin')->addHour();

        $proof->update([
            'review_status'=>'rejected',
            'review_comment'=>$data['review_comment'],
            'rejection_kind'=>$data['rejection_kind'],
            'resubmit_due_at'=>$due,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
        ]);

        $order=$proof->orderDay->order()->with('user')->firstOrFail();
        $workflow->createViolation(
            $order,
            'insufficient_evidence',
            $data['review_comment'],
            $proof->order_day_id,
            [
                'proof_submission_id'=>$proof->id,
                'rejection_kind'=>$data['rejection_kind'],
                'retake_can_cure_violation'=>$request->boolean('retake_can_cure_violation'),
                'resubmit_due_at'=>$due->toIso8601String(),
            ]
        );

        $notifications->send(
            $order->user,
            'proof_rejected',
            'Nachweis nicht ausreichend',
            $data['review_comment'].' Erneute Aufnahme bis '.$due->format('d.m.Y H:i').' Uhr. Der mögliche Verstoß wird separat geprüft.',
            route('orders.show',$order)
        );

        return back()->with('success','Nachweis wurde beanstandet; Nachforderung und möglicher Verstoß wurden angelegt.');
    }

    public function grantExtraRetry(Request $request, ProofSubmission $proof, NotificationService $notifications)
    {
        abort_unless($proof->review_status==='rejected',422,'Eine Nachforderung ist nur bei einem beanstandeten Nachweis möglich.');
        $proof->update([
            'resubmit_due_at'=>now('Europe/Berlin')->addHour(),
            'extra_retry_granted'=>true,
        ]);

        $notifications->send(
            $proof->orderDay->order->user,
            'proof_retake_extended',
            'Nachweisfrist neu gesetzt',
            'Für Auftrag #'.$proof->orderDay->order->order_number.' wurde eine neue einstündige Nachreichfrist gesetzt.',
            route('orders.show',$proof->orderDay->order)
        );

        return back()->with('success','Neue einstündige Nachreichfrist wurde gesetzt.');
    }

    private function refreshDayStatus(ProofSubmission $proof): void
    {
        $day=$proof->orderDay()->with('order','proofs')->firstOrFail();
        if($day->day_number===0) return;

        $windows=is_array($day->plan) && count($day->plan)
            ? $day->plan
            : data_get($day->order->current_requirements ?: $day->order->offer_snapshot,'proof_requirements',[]);

        $complete=true;
        foreach(is_array($windows)?$windows:[] as $window){
            $required=(int)($window['required_images']??0);
            if($required<=0) continue;
            $accepted=$day->proofs
                ->where('window_key',(string)($window['key']??''))
                ->where('review_status','accepted')
                ->count();
            if($accepted<$required){
                $complete=false;
                break;
            }
        }

        if($complete) $day->update(['status'=>'accepted']);
    }
}
