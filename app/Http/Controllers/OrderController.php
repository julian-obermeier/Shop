<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Order;
use App\Services\ConsentService;
use App\Services\OrderService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        $orders=request()->user()->orders()->with('offer','days.proofs','shipment','precheck')->latest()->paginate(20);
        return view('orders.index',compact('orders'));
    }

    public function show(Order $order)
    {
        abort_unless($order->user_id===request()->user()->id || request()->user()->isAdmin(),403);
        $order->load(
            'options','fieldValues','days.proofs','statusHistory','precheck','shipment',
            'goodsReceipt','goodsInspection','conversation','proofChallenges','shipment.evidences'
        );
        return view('orders.show',compact('order'));
    }

    public function store(Request $request, Offer $offer, V1OrderWorkflowService $workflow, ConsentService $consents)
    {
        abort_unless($request->user()->hasVerifiedEmail(),422,'Bitte bestätige zuerst deine E-Mail-Adresse.');
        $consents->assertRequiredConsents($request->user());

        $data=$request->validate([
            'options'=>['nullable','array'],
            'options.*'=>['integer'],
            'fields'=>['nullable','array'],
            'confirm_summary'=>['accepted'],
            'confirm_rights'=>['nullable','accepted'],
        ]);

        $isDigital=$offer->category()->where('kind','digital')->exists();
        if($isDigital){
            abort_unless($request->boolean('confirm_rights'),422,'Bitte bestätige die Rechtevereinbarung für diesen digitalen Auftrag.');
        }

        $order=$workflow->accept(
            $request->user(),
            $offer,
            $data['options']??[],
            $data['fields']??[],
            $request->boolean('confirm_rights')
        );

        return redirect()->route('orders.show',$order)->with(
            'success',
            $isDigital
                ? 'Auftrag wurde verbindlich angenommen. Du kannst jetzt mit der digitalen Ausarbeitung beginnen.'
                : 'Auftrag wurde verbindlich angenommen. Reiche jetzt die erforderliche Vorabkontrolle ein.'
        );
    }

    public function complete(Order $order, OrderService $service)
    {
        $service->completeExecution($order,request()->user());
        return back()->with('success','Die Erfüllungsphase ist abgeschlossen. Der nächste vorgesehene Abschluss-/Versandschritt ist jetzt verfügbar.');
    }
}
