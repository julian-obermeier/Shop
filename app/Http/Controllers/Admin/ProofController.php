<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProofSubmission;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProofController extends Controller
{
    public function index()
    {
        $proofs=ProofSubmission::with('orderDay.order.user')
            ->where('review_status','pending')
            ->whereNull('purged_at')
            ->latest()->paginate(40);
        return view('admin.proofs.index',compact('proofs'));
    }

    public function file(ProofSubmission $proof): StreamedResponse
    {
        abort_if($proof->purged_at,410,'Diese Datei wurde nach Ablauf der Aufbewahrungsfrist gelöscht.');
        abort_unless(Storage::disk('proofs')->exists($proof->storage_path),404);
        return Storage::disk('proofs')->download($proof->storage_path,$proof->original_name);
    }

    public function review(Request $request, ProofSubmission $proof, AuditService $audit, NotificationService $notifications)
    {
        abort_if($proof->purged_at,422,'Dieser Nachweis wurde bereits aus dem Dateispeicher entfernt.');
        $data=$request->validate([
            'review_status'=>['required','in:accepted,rejected,resubmit'],
            'review_comment'=>['nullable','string','max:1000'],
        ]);
        $before=$proof->toArray();
        $proof->update($data+['reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]);
        $audit->log('proof.reviewed',$proof,$before,$proof->fresh()->toArray());

        $order=$proof->orderDay->order;
        if($data['review_status']!=='accepted'){
            $notifications->send(
                $order->user,
                'proof_'.$data['review_status'],
                'Nachweis '.strtoupper($data['review_status']),
                $data['review_comment'] ?: 'Ein Nachweis zu Auftrag #'.$order->order_number.' benötigt Aufmerksamkeit.',
                route('orders.show',$order)
            );
        }

        return back()->with('success','Nachweis wurde geprüft.');
    }
}
