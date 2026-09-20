<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\V1FinalizationService;
use Illuminate\Http\Request;

class GoodsInspectionController extends Controller
{
    public function store(Request $request, Order $order, NotificationService $notifications, V1FinalizationService $finalization)
    {
        abort_unless($order->status==='inspection',422,'Die Warenprüfung ist in diesem Status nicht möglich.');
        abort_unless($order->readyForFinalInspection(),422,'Die finale Warenprüfung ist erst nach vollständiger Ausführung, akzeptiertem Versandnachweis und bestätigtem Wareneingang möglich.');

        $data=$request->validate([
            'result'=>['required','in:accepted,partial,rejected'],
            'amount'=>['nullable','numeric','min:0'],
            'reason'=>['nullable','string','max:3000','required_unless:result,accepted'],
            'seller_message'=>['nullable','string','max:3000'],
            'internal_note'=>['nullable','string','max:3000'],
        ]);

        $amount=match($data['result']){
            'accepted'=>(float)$order->compensation_total,
            'partial'=>(float)($data['amount']??0),
            'rejected'=>0.0,
        };
        if($data['result']==='partial') abort_if($amount<=0 || $amount>=(float)$order->compensation_total,422,'Bei Teilannahme muss der Betrag größer als 0 und kleiner als der volle Auftragswert sein.');

        $order->goodsInspection()->updateOrCreate([],[
            'reviewed_by'=>$request->user()->id,
            'categories'=>null,
            'base_percentage'=>$data['result']==='accepted'?100:null,
            'extra_results'=>null,
            'calculated_compensation'=>$amount,
            'result'=>$data['result']==='partial'?'partial':$data['result'],
            'reason'=>$data['reason']??null,
            'reviewed_at'=>now(),
        ]);

        $finalization->finalize($order,$request->user(),$amount,$data['result'],$data['reason']??null);

        $message=$data['seller_message']??null;
        if(!$message){
            $message=match($data['result']){
                'accepted'=>'Auftrag #'.$order->order_number.' wurde vollständig akzeptiert. '.number_format($amount,2,',','.').' € sind jetzt im Wallet verfügbar.',
                'partial'=>'Auftrag #'.$order->order_number.' wurde teilweise akzeptiert. Freigegeben: '.number_format($amount,2,',','.').' €. Grund: '.$data['reason'],
                'rejected'=>'Auftrag #'.$order->order_number.' wurde endgültig abgelehnt. Grund: '.$data['reason'],
            };
        }

        $notifications->send($order->user,'final_review_'.$data['result'],'Abschlussprüfung',$message,route('orders.show',$order));
        return back()->with('success','Abschlussprüfung wurde gespeichert und die Wallet-Reservierung entsprechend abgeschlossen.');
    }
}
