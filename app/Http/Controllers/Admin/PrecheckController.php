<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderPrecheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrecheckController extends Controller
{
    public function index()
    {
        $prechecks = OrderPrecheck::with('order.user')->where('status','submitted')->latest('submitted_at')->paginate(30);
        return view('admin.prechecks.index', compact('prechecks'));
    }

    public function file(OrderPrecheck $precheck): StreamedResponse
    {
        abort_unless($precheck->photo_path,404);
        return Storage::disk('prechecks')->download($precheck->photo_path);
    }

    public function review(Request $request, OrderPrecheck $precheck)
    {
        $data = $request->validate([
            'status'=>['required','in:accepted,resubmit,rejected'],
            'admin_comment'=>['nullable','string','max:2000'],
        ]);

        $precheck->update([
            'status'=>$data['status'],
            'admin_comment'=>$data['admin_comment'] ?? null,
            'reviewed_by'=>$request->user()->id,
            'reviewed_at'=>now(),
        ]);

        $order = $precheck->order;
        $from = $order->status;
        $to = match($data['status']) {
            'accepted' => 'approved',
            'resubmit' => 'precheck_resubmit',
            default => 'rejected',
        };
        $order->update(['status'=>$to]);
        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>$to,
            'reason'=>$data['admin_comment'] ?? 'Vorprüfung bearbeitet',
        ]);

        return back()->with('success','Vorprüfung wurde bearbeitet.');
    }
}
