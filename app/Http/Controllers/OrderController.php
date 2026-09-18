<?php
namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Order;
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
        $order->load('options','days.proofs','statusHistory','precheck','shipment','goodsReceipt','conversation');
        return view('orders.show',compact('order'));
    }

    public function store(Request $request, Offer $offer, OrderService $service)
    {
        abort_unless($request->user()->verified_at, 422, 'Vor der Annahme eines Angebots ist eine abgeschlossene Verifizierung erforderlich.');
        abort_unless($offer->active, 404);
        $data=$request->validate(['options'=>['array'],'options.*'=>['integer']]);
        $order=$service->create($request->user(),$offer,$data['options']??[]);
        return redirect()->route('orders.show',$order)->with('success','Auftrag wurde angelegt.');
    }

    public function start(Order $order, OrderService $service)
    {
        abort_unless($order->user_id===request()->user()->id,403);
        abort_unless(request()->user()->verified_at,422,'Die Verifizierung muss abgeschlossen sein.');
        $service->start($order);
        return back()->with('success','Die Erfüllungsphase wurde gestartet.');
    }

    public function complete(Order $order)
    {
        abort_unless($order->user_id===request()->user()->id,403);
        abort_unless($order->status==='active',422,'Der Auftrag ist nicht in der aktiven Erfüllungsphase.');

        $order->load('days.proofs');
        $complete=$order->days->isNotEmpty() && $order->days->every(
            fn($day) => $day->proofs->count() >= $day->required_proofs
        );
        abort_unless($complete,422,'Es fehlen noch erforderliche Nachweise.');

        DB::transaction(function() use($order) {
            $order->update(['status'=>'waiting_shipping']);
            $order->statusHistory()->create([
                'changed_by'=>auth()->id(),
                'from_status'=>'active',
                'to_status'=>'waiting_shipping',
                'reason'=>'Erfüllungsphase durch Anbieterin abgeschlossen',
            ]);
        });

        return back()->with('success','Die Erfüllungsphase ist abgeschlossen. Der Auftrag ist jetzt versandbereit.');
    }
}
