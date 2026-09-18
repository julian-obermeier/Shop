<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Order;
use App\Services\ConsentService;
use App\Services\OrderService;
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
            'goodsReceipt','goodsInspection','returnRequest','conversation','proofChallenges'
        );
        return view('orders.show',compact('order'));
    }

    public function store(Request $request, Offer $offer, OrderService $service, ConsentService $consents)
    {
        abort_if($request->user()->hasRestriction('offers'),422,'Die Annahme neuer Angebote ist für dieses Konto derzeit gesperrt.');
        abort_unless($request->user()->hasVerifiedEmail(),422,'Bitte bestätige zuerst deine E-Mail-Adresse.');
        $consents->assertRequiredConsents($request->user());

        $data=$request->validate([
            'options'=>['nullable','array'],
            'options.*'=>['integer'],
            'fields'=>['nullable','array'],
            'proposed_start_date'=>['required','date'],
            'confirm_summary'=>['accepted'],
        ]);

        $order=$service->create(
            $request->user(),
            $offer,
            $data['options']??[],
            $data['fields']??[],
            $data['proposed_start_date']
        );

        return redirect()->route('orders.show',$order)->with('success','Auftragsanfrage wurde eingereicht. Der Starttermin ist erst nach Adminbestätigung verbindlich.');
    }

    public function acceptDate(Order $order, OrderService $service)
    {
        abort_unless($order->user_id===request()->user()->id,403);
        $service->acceptAlternateDate($order,request()->user());
        return back()->with('success','Der vorgeschlagene Starttermin wurde verbindlich bestätigt.');
    }

    public function withdraw(Order $order)
    {
        abort_unless($order->user_id===request()->user()->id,403);
        abort_unless(in_array($order->status,['requested','awaiting_date_confirmation'],true),422,'Nur noch nicht bestätigte Anfragen können folgenlos zurückgezogen werden.');

        DB::transaction(function() use($order){
            $from=$order->status;
            $order->update(['status'=>'cancelled','completed_at'=>now()]);
            $order->statusHistory()->create([
                'changed_by'=>auth()->id(),
                'from_status'=>$from,
                'to_status'=>'cancelled',
                'reason'=>'Auftragsanfrage vor Adminbestätigung zurückgezogen',
            ]);
        });

        return redirect()->route('orders.index')->with('success','Auftragsanfrage wurde zurückgezogen.');
    }

    public function start(Order $order, OrderService $service)
    {
        abort_unless($order->user_id===request()->user()->id,403);
        abort_if(request()->user()->hasRestriction('offers'),422,'Auftragsstarts sind für dieses Konto derzeit gesperrt.');
        abort_unless(request()->user()->hasVerifiedEmail(),422,'Bitte bestätige zuerst deine E-Mail-Adresse.');

        $service->prepareStart($order,request()->user());

        return back()->with('success','Aktivierung bestätigt. Erzeuge jetzt den 10-Minuten-Code und nimm das verpflichtende Startfoto auf.');
    }

    public function complete(Order $order, OrderService $service)
    {
        $service->completeExecution($order,request()->user());
        return back()->with('success','Die Erfüllungsphase ist abgeschlossen. Der Versand muss innerhalb von 24 Stunden erfolgen.');
    }

    public function abort(Request $request, Order $order, OrderService $service)
    {
        $data=$request->validate(['reason'=>['required','string','max:2000']]);
        $service->voluntaryAbort($order,request()->user(),$data['reason']);
        return redirect()->route('orders.show',$order)->with('success','Der Auftrag wurde freiwillig abgebrochen. Für diesen Auftrag wird keine Vergütung gezahlt.');
    }
}
