<?php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    public function store(Request $request, Order $order)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless(in_array($order->status,['waiting_shipping','shipping_overdue'],true),422,'Der Auftrag ist noch nicht versandbereit.');

        $data=$request->validate([
            'carrier'=>['required','string','max:100'],
            'tracking_number'=>['required','string','max:150'],
        ]);

        DB::transaction(function() use($order,$request,$data){
            $from=$order->status;

            $order->shipment()->updateOrCreate([],[
                'carrier'=>$data['carrier'],
                'tracking_number'=>$data['tracking_number'],
                'status'=>'shipped',
                'shipped_at'=>now(),
            ]);

            $order->update(['status'=>'shipped']);
            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>$from,
                'to_status'=>'shipped',
                'reason'=>$from==='shipping_overdue'?'Versand nach Fristüberschreitung gemeldet':'Versand gemeldet',
            ]);
        });

        return back()->with('success','Der Versand wurde gespeichert.');
    }
}
