<?php
namespace App\Http\Controllers;
use App\Models\Offer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
class OrderController extends Controller {
    public function index(){ $orders=request()->user()->orders()->with('offer','days.proofs')->latest()->paginate(20); return view('orders.index',compact('orders')); }
    public function show(Order $order){ abort_unless($order->user_id===request()->user()->id || request()->user()->isAdmin(),403); $order->load('options','days.proofs','statusHistory'); return view('orders.show',compact('order')); }
    public function store(Request $request, Offer $offer, OrderService $service){
        $data=$request->validate(['options'=>['array'],'options.*'=>['integer']]); $order=$service->create($request->user(),$offer,$data['options']??[]); return redirect()->route('orders.show',$order)->with('success','Auftrag wurde angelegt.');
    }
    public function start(Order $order, OrderService $service){ abort_unless($order->user_id===request()->user()->id,403); $service->start($order); return back()->with('success','Die Erfüllungsphase wurde gestartet.'); }
}
