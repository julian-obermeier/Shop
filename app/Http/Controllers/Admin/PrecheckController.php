<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderPrecheck;
use App\Services\NotificationService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrecheckController extends Controller
{
    public function index()
    {
        $prechecks=OrderPrecheck::with('order.user')
            ->whereIn('status',['submitted','resubmit'])
            ->latest('submitted_at')
            ->paginate(30);
        return view('admin.prechecks.index',compact('prechecks'));
    }

    public function evidence(int $evidence): StreamedResponse
    {
        $row=DB::table('order_precheck_evidences')->where('id',$evidence)->first();
        abort_unless($row,404);
        abort_unless(Storage::disk('prechecks')->exists($row->storage_path),404);
        return Storage::disk('prechecks')->download($row->storage_path);
    }

    public function reviewEvidence(
        Request $request,
        OrderPrecheck $precheck,
        int $evidence,
        V1OrderWorkflowService $workflow,
        NotificationService $notifications
    ) {
        $data=$request->validate([
            'status'=>['required','in:accepted,resubmit'],
            'rejection_kind'=>['nullable','string','max:50'],
            'admin_comment'=>['nullable','string','max:2000'],
            'resubmit_due_at'=>['nullable','date'],
        ]);

        $order=$precheck->order()->with('user')->firstOrFail();
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Der Auftrag befindet sich nicht mehr in der Vorabkontrolle.');

        $runId=DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$order->series_number)->value('id');
        $row=DB::table('order_precheck_evidences')
            ->where('id',$evidence)
            ->where('order_id',$order->id)
            ->where('order_run_id',$runId)
            ->first();
        abort_unless($row,404);
        abort_unless($row->status==='submitted',422,'Dieser Nachweis wurde bereits bewertet.');

        DB::table('order_precheck_evidences')->where('id',$evidence)->update([
            'status'=>$data['status'],
            'rejection_kind'=>$data['status']==='resubmit'?($data['rejection_kind']??'other'):null,
            'admin_comment'=>$data['admin_comment']??null,
            'resubmit_due_at'=>$data['status']==='resubmit'?($data['resubmit_due_at']??now()->addHour()):null,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
            'updated_at'=>now(),
        ]);

        if($data['status']==='resubmit'){
            $precheck->update(['status'=>'resubmit','admin_comment'=>$data['admin_comment']??null]);
            $order->update(['status'=>'precheck_resubmit']);
            $notifications->send(
                $order->user,
                'precheck_resubmit',
                'Vorabfoto erneut erforderlich',
                'Für Auftrag #'.$order->order_number.' muss der Nachweis „'.$row->label.'“ erneut aufgenommen werden.'.(!empty($data['admin_comment'])?' '.$data['admin_comment']:''),
                route('orders.show',$order)
            );
            return back()->with('success','Nachaufnahme wurde angefordert.');
        }

        $slots=data_get($order->offer_snapshot,'category_config.precheck_slots',[]);
        if(!is_array($slots) || count($slots)===0) $slots=[['key'=>'item','label'=>'Konkreter Artikel']];

        $latest=DB::table('order_precheck_evidences')
            ->where('order_id',$order->id)
            ->where('order_run_id',$runId)
            ->orderByDesc('id')
            ->get()
            ->unique('slot_key')
            ->keyBy('slot_key');

        $allAccepted=collect($slots)->every(function($slot) use($latest){
            $entry=$latest->get($slot['key']);
            return $entry && $entry->status==='accepted';
        });

        if($allAccepted){
            $precheck->update([
                'status'=>'accepted',
                'reviewed_by'=>$request->user()->id,
                'reviewed_at'=>now(),
                'admin_comment'=>null,
            ]);
            $workflow->activateAfterPrecheck($order,$request->user());
            $notifications->send(
                $order->user,
                'precheck_accepted',
                'Vorabkontrolle freigegeben – Auftrag gestartet',
                'Alle Vorabnachweise für Auftrag #'.$order->order_number.' wurden akzeptiert. Der Auftrag ist jetzt gestartet.',
                route('orders.show',$order)
            );
            return back()->with('success','Alle Vorabnachweise sind freigegeben. Der Auftrag wurde unmittelbar gestartet.');
        }

        return back()->with('success','Vorabfoto wurde akzeptiert. Weitere Nachweise sind noch offen.');
    }

    public function reject(Request $request, OrderPrecheck $precheck, NotificationService $notifications)
    {
        $data=$request->validate(['reason'=>['required','string','max:2000']]);
        $order=$precheck->order()->with('user')->firstOrFail();
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422);

        $precheck->update(['status'=>'rejected','admin_comment'=>$data['reason'],'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]);
        $from=$order->status;
        $order->update(['status'=>'rejected','phase'=>'archive','final_compensation'=>0,'completed_at'=>now(),'archived_at'=>now()]);
        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>'rejected',
            'reason'=>$data['reason'],
        ]);

        $notifications->send($order->user,'precheck_rejected','Auftrag abgelehnt','Auftrag #'.$order->order_number.' wurde endgültig abgelehnt. Grund: '.$data['reason'],route('orders.show',$order));
        return back()->with('success','Auftrag wurde endgültig abgelehnt und archiviert.');
    }
}
