<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderPrecheck;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrecheckController extends Controller
{
    public function index()
    {
        $prechecks=OrderPrecheck::with('order.user')->where('status','submitted')->latest('submitted_at')->paginate(30);
        return view('admin.prechecks.index',compact('prechecks'));
    }

    public function file(OrderPrecheck $precheck): StreamedResponse
    {
        abort_unless($precheck->photo_path,404);
        return Storage::disk('prechecks')->download($precheck->photo_path);
    }

    public function review(Request $request, OrderPrecheck $precheck, AuditService $audit, NotificationService $notifications)
    {
        $data=$request->validate(['status'=>['required','in:accepted,resubmit,rejected'],'admin_comment'=>['nullable','string','max:2000']]);
        $before=$precheck->toArray();

        $precheck->update([
            'status'=>$data['status'],
            'admin_comment'=>$data['admin_comment']??null,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
        ]);

        $order=$precheck->order;
        $from=$order->status;
        $to=match($data['status']){'accepted'=>'approved','resubmit'=>'precheck_resubmit',default=>'rejected'};
        $order->update(['status'=>$to]);
        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>$to,
            'reason'=>$data['admin_comment']??'Vorprüfung bearbeitet',
        ]);

        $audit->log('precheck.reviewed',$precheck,$before,$precheck->fresh()->toArray());
        $notifications->send(
            $order->user,
            'precheck_'.$data['status'],
            'Vorprüfung '.strtoupper($data['status']),
            $data['admin_comment'] ?: 'Die Vorprüfung zu Auftrag #'.$order->order_number.' wurde bearbeitet.',
            route('orders.show',$order)
        );

        return back()->with('success','Vorprüfung wurde bearbeitet.');
    }
}
