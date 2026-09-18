<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\WalletService;
use Illuminate\Http\Request;
class OrderController extends Controller {
    public function index(Request $request){ $q=Order::with('user','offer'); if($request->filled('status'))$q->where('status',$request->status); $orders=$q->latest()->paginate(30)->withQueryString(); return view('admin.orders.index',compact('orders')); }
    public function show(Order $order){ $order->load('user','offer','options','days.proofs','statusHistory'); return view('admin.orders.show',compact('order')); }
    public function status(Request $request, Order $order){ $data=$request->validate(['status'=>['required','string','max:40'],'reason'=>['nullable','string','max:1000']]); $from=$order->status; $order->update(['status'=>$data['status']]); $order->statusHistory()->create(['changed_by'=>$request->user()->id,'from_status'=>$from,'to_status'=>$data['status'],'reason'=>$data['reason']??null]); return back()->with('success','Status wurde geändert.'); }
    public function release(Order $order, WalletService $wallet){ $wallet->release($order); return back()->with('success','Vergütung wurde freigegeben.'); }
}
