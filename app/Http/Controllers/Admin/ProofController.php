<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProofSubmission;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\ReliabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
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

    public function review(Request $request, ProofSubmission $proof, AuditService $audit, NotificationService $notifications, OrderService $orders, ReliabilityService $reliability)
    {
        $data=$request->validate([
            'review_status'=>['required','in:accepted,rejected'],
            'review_comment'=>['nullable','string','max:1000','required_if:review_status,rejected'],
            'rejection_kind'=>['nullable','in:technical,non_reproducible','required_if:review_status,rejected'],
        ]);

        $before=$proof->toArray();

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
        } else {
            $technical=$data['rejection_kind']==='technical';
            $regularRetryAvailable=$technical && (int)$proof->retry_number < 2;
            $proof->update([
                'review_status'=>'rejected',
                'review_comment'=>$data['review_comment'],
                'rejection_kind'=>$data['rejection_kind'],
                'resubmit_due_at'=>$regularRetryAvailable?now()->addHours(2):null,
                'reviewed_by'=>$request->user()->id,
                'reviewed_at'=>now(),
            ]);

            if($technical){
                $order=$proof->orderDay->order()->with('user')->firstOrFail();
                $order->update([
                    'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                    'last_reliability_issue'=>'Technisch/formal abgelehnter Nachweis mit Nachreichung',
                ]);
                $reliability->recordViolation(
                    $order->user,
                    $order->fresh(),
                    'proof_resubmission',
                    'Technisch/formal abgelehnter Nachweis: '.$data['review_comment'],
                    ['proof_submission_id'=>$proof->id]
                );
            } else {
                $orders->invalidateDay($proof->orderDay,$data['review_comment']);
            }
        }

        $audit->log('proof.reviewed',$proof,$before,$proof->fresh()->toArray());

        $order=$proof->orderDay->order;
        if($data['review_status']==='rejected'){
            $notifications->send(
                $order->user,
                'proof_rejected',
                'Nachweis abgelehnt',
                $data['rejection_kind']==='technical'
                    ? ((int)$proof->retry_number < 2
                        ? $data['review_comment'].' Du hast ab der Ablehnung 2 Stunden Zeit für die reguläre Nachreichung.'
                        : $data['review_comment'].' Die zwei regulären Nachreichversuche sind ausgeschöpft. Ein weiterer Versuch ist nur nach ausdrücklicher Adminfreigabe möglich.')
                    : $data['review_comment'].' Der Nachweis ist nicht reproduzierbar; der Tag wird nach den Auftragsregeln behandelt.',
                route('orders.show',$order)
            );
        }

        return back()->with('success','Nachweis wurde geprüft.');
    }

    public function grantExtraRetry(Request $request, ProofSubmission $proof, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($proof->review_status==='rejected',422,'Zusatzversuche können nur nach einer Ablehnung freigegeben werden.');
        abort_unless($proof->rejection_kind==='technical',422,'Ein Zusatzversuch ist nur bei einem technisch/formal nachreichbaren Fehler möglich.');
        abort_unless((int)$proof->retry_number>=2,422,'Zunächst müssen die zwei regulären Nachreichversuche ausgeschöpft sein.');
        abort_if($proof->extra_retry_granted && $proof->resubmit_due_at?->isFuture(),422,'Für diesen Nachweis ist bereits ein zusätzlicher Versuch freigegeben.');

        $before=$proof->toArray();
        $proof->update([
            'extra_retry_granted'=>true,
            'resubmit_due_at'=>now()->addHours(2),
        ]);
        $audit->log('proof.extra_retry.granted',$proof,$before,$proof->fresh()->toArray());

        $notifications->send(
            $proof->orderDay->order->user,
            'proof_extra_retry',
            'Zusätzlicher Nachweisversuch freigegeben',
            'Für Auftrag #'.$proof->orderDay->order->order_number.' wurde ein weiterer Nachreichversuch freigegeben. Die Frist beträgt 2 Stunden.',
            route('orders.show',$proof->orderDay->order)
        );

        return back()->with('success','Zusätzlicher Nachreichversuch wurde für 2 Stunden freigegeben.');
    }

    private function refreshDayStatus(ProofSubmission $proof): void
    {
        $day=$proof->orderDay()->with('order','proofs')->firstOrFail();

        if($day->day_number===0){
            if($day->proofs->where('review_status','accepted')->count()>=1) $day->update(['status'=>'accepted']);
            return;
        }

        $windows=data_get($day->order->current_requirements ?: $day->order->offer_snapshot,'proof_requirements',[]);
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
