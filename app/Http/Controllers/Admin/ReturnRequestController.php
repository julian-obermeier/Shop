<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnRequestController extends Controller
{
    public function label(ReturnRequest $returnRequest): StreamedResponse
    {
        abort_unless($returnRequest->return_label_path && Storage::disk('returns')->exists($returnRequest->return_label_path),404);
        return Storage::disk('returns')->download($returnRequest->return_label_path);
    }

    public function quote(Request $request, ReturnRequest $returnRequest, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($returnRequest->status==='awaiting_quote_payment',422,'Für diese Rücksendung kann aktuell kein Kostenbetrag hinterlegt werden.');
        abort_if($returnRequest->fulfillment_due_at->isPast(),422,'Die 24-Stunden-Frist ist bereits abgelaufen.');

        $data=$request->validate(['amount'=>['required','numeric','min:0.01','max:1000']]);
        $before=$returnRequest->toArray();
        $returnRequest->update(['requested_shipping_cost'=>round((float)$data['amount'],2)]);

        $audit->log('return.shipping_cost.quoted',$returnRequest,$before,$returnRequest->fresh()->toArray());
        $notifications->send(
            $returnRequest->user,
            'return_shipping_cost',
            'Rücksendekosten mitgeteilt',
            'Für die Rücksendung zu Auftrag #'.$returnRequest->order->order_number.' sind '.number_format((float)$returnRequest->requested_shipping_cost,2,',','.').' € separat zu überweisen. Die bestehende 24-Stunden-Frist endet am '.$returnRequest->fulfillment_due_at->format('d.m.Y H:i').' Uhr.',
            route('orders.show',$returnRequest->order)
        );

        return back()->with('success','Rücksendekosten wurden mitgeteilt.');
    }

    public function confirmPayment(ReturnRequest $returnRequest, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($returnRequest->status==='awaiting_quote_payment',422,'Diese Rücksendung wartet nicht auf eine Kostenzahlung.');
        abort_unless($returnRequest->requested_shipping_cost,422,'Es wurden noch keine Rücksendekosten hinterlegt.');
        abort_if($returnRequest->fulfillment_due_at->isPast(),422,'Die 24-Stunden-Frist ist bereits abgelaufen.');

        $before=$returnRequest->toArray();
        $returnRequest->update([
            'status'=>'ready',
            'shipping_cost_paid_at'=>now(),
        ]);

        $audit->log('return.shipping_cost.paid',$returnRequest,$before,$returnRequest->fresh()->toArray());
        $notifications->send(
            $returnRequest->user,
            'return_ready',
            'Rücksendekosten bestätigt',
            'Die Rücksendekosten für Auftrag #'.$returnRequest->order->order_number.' wurden bestätigt. Die Rücksendung kann durch den Betreiber ausgeführt werden.',
            route('orders.show',$returnRequest->order)
        );

        return back()->with('success','Zahlung wurde bestätigt; Rücksendung ist versandbereit.');
    }

    public function complete(Request $request, ReturnRequest $returnRequest, AuditService $audit, NotificationService $notifications)
    {
        abort_unless($returnRequest->status==='ready',422,'Die Rücksendung ist noch nicht versandbereit.');
        $data=$request->validate([
            'tracking_number'=>['nullable','string','max:180'],
        ]);

        $before=$returnRequest->toArray();
        $returnRequest->update([
            'status'=>'returned',
            'tracking_number'=>$data['tracking_number']??null,
            'returned_at'=>now(),
        ]);

        $audit->log('return.shipped',$returnRequest,$before,$returnRequest->fresh()->toArray());
        $notifications->send(
            $returnRequest->user,
            'return_shipped',
            'Ware zurückgesendet',
            'Die abgelehnte Ware zu Auftrag #'.$returnRequest->order->order_number.' wurde zurückgesendet'.(!empty($data['tracking_number'])?' (Tracking: '.$data['tracking_number'].')':'').'.',
            route('orders.show',$returnRequest->order)
        );

        return back()->with('success','Rücksendung wurde als ausgeführt markiert.');
    }
}
