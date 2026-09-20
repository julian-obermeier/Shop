<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Order;
use App\Services\ConsentService;
use App\Services\OrderService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $runId=DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$order->series_number)->value('id');
        $precheckSlots=data_get($order->offer_snapshot,'category_config.precheck_slots',[]);
        if(!is_array($precheckSlots) || count($precheckSlots)===0) $precheckSlots=[['key'=>'item','label'=>'Konkreter Artikel']];

        $precheckEvidence=DB::table('order_precheck_evidences')
            ->where('order_id',$order->id)
            ->where('order_run_id',$runId)
            ->orderByDesc('id')
            ->get()
            ->unique('slot_key')
            ->keyBy('slot_key');

        $violations=DB::table('violations')->where('order_id',$order->id)->latest('id')->get();
        $extensionDays=DB::table('extension_days')->where('order_id',$order->id)->orderBy('sequence_no')->get();
        $damageCases=DB::table('damage_cases')->where('order_id',$order->id)->latest('id')->get();
        $digitalComponent=DB::table('digital_components')->where('order_id',$order->id)->first();

        return view('orders.show',compact(
            'order','precheckSlots','precheckEvidence','violations','extensionDays','damageCases','digitalComponent'
        ));
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
