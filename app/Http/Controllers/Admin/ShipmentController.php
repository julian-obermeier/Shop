<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvidence;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\ReliabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    public function evidence(ShipmentEvidence $evidence): StreamedResponse
    {
        abort_unless(Storage::disk('shipments')->exists($evidence->storage_path),404);
        return Storage::disk('shipments')->download($evidence->storage_path,$evidence->original_name);
    }

    public function review(Request $request, Shipment $shipment, AuditService $audit, NotificationService $notifications, ReliabilityService $reliability)
    {
        $data=$request->validate([
            'review_status'=>['required','in:accepted,rejected'],
            'review_comment'=>['nullable','string','max:1000','required_if:review_status,rejected'],
        ]);

        $before=$shipment->toArray();

        $shipment->update([
            'review_status'=>$data['review_status'],
            'review_comment'=>$data['review_comment']??null,
            'resubmit_due_at'=>$data['review_status']==='rejected'?now()->addHours(2):null,
        ]);

        if($data['review_status']==='rejected'){
            $order=$shipment->order()->with('user')->firstOrFail();
            $order->update([
                'reliability_issue_count'=>DB::raw('reliability_issue_count + 1'),
                'last_reliability_issue'=>'Versandnachweis abgelehnt und Nachreichung erforderlich',
            ]);
            $reliability->recordViolation(
                $order->user,
                $order->fresh(),
                'shipment_evidence_rejected',
                'Versandnachweis abgelehnt: '.$data['review_comment'],
                ['shipment_id'=>$shipment->id]
            );
        }

        $audit->log('shipment.evidence.reviewed',$shipment,$before,$shipment->fresh()->toArray());

        $notifications->send(
            $shipment->order->user,
            'shipment_evidence_'.$data['review_status'],
            $data['review_status']==='accepted'?'Versandnachweis akzeptiert':'Versandnachweis erneut erforderlich',
            $data['review_status']==='accepted'
                ? 'Die Versandnachweise zu Auftrag #'.$shipment->order->order_number.' wurden akzeptiert.'
                : $data['review_comment'].' Bitte nimm Paketfoto und Versandbeleg innerhalb von 2 Stunden erneut über die Live-Kamera auf.',
            route('orders.show',$shipment->order),
            ['order_id'=>$shipment->order_id,'shipment_id'=>$shipment->id]
        );

        return back()->with('success','Versandnachweis wurde geprüft.');
    }
}
