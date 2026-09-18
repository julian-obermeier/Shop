<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoodsReceiptController extends Controller
{
    public function store(Request $request, Order $order)
    {
        abort_unless(in_array($order->status,['shipped','received'],true),422,'Wareneingang ist in diesem Status nicht möglich.');

        $data = $request->validate([
            'complete'=>['required','boolean'],
            'note'=>['nullable','string','max:2000'],
        ]);

        DB::transaction(function() use($request,$order,$data) {
            $order->goodsReceipt()->updateOrCreate([],[
                'received_by'=>$request->user()->id,
                'status'=>$data['complete'] ? 'received' : 'partial',
                'complete'=>$data['complete'],
                'note'=>$data['note'] ?? null,
                'received_at'=>now(),
            ]);
            $from=$order->status;
            $to=$data['complete'] ? 'inspection' : 'received';
            $order->update(['status'=>$to,'received_at'=>now()]);
            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>$from,
                'to_status'=>$to,
                'reason'=>$data['note'] ?? 'Wareneingang erfasst',
            ]);
        });

        return back()->with('success','Wareneingang wurde erfasst.');
    }
}
