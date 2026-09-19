<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReturnRequestController extends Controller
{
    public function store(Request $request, Order $order, NotificationService $notifications)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless($order->status==='rejected' && $order->goodsInspection?->result==='rejected',422,'Für diesen Auftrag ist keine Rücksendung verfügbar.');
        abort_if($order->returnRequest,422,'Für diesen Auftrag wurde bereits eine Rücksendung angefordert.');

        $decisionDeadline=$order->goodsInspection->reviewed_at
            ->copy()->timezone('Europe/Berlin')->startOfDay()->addDays(3)->endOfDay();
        abort_if(now('Europe/Berlin')->gt($decisionDeadline),422,'Die 3-Kalendertage-Frist für die Rücksendeanforderung ist abgelaufen.');

        $data=$request->validate([
            'method'=>['required','in:own_label,operator_quote'],
            'return_label'=>['nullable','file','mimes:pdf,jpg,jpeg,png,webp','max:10240','required_if:method,own_label'],
        ]);

        $path=null;
        if($data['method']==='own_label'){
            $file=$data['return_label'];
            $extension=strtolower($file->getClientOriginalExtension() ?: 'pdf');
            $path=$file->storeAs($order->order_number,Str::uuid().'.'.$extension,'returns');
        }

        $return=$order->returnRequest()->create([
            'user_id'=>$request->user()->id,
            'status'=>$data['method']==='own_label'?'ready':'awaiting_quote_payment',
            'requested_at'=>now(),
            'fulfillment_due_at'=>now()->addHours(24),
            'method'=>$data['method'],
            'return_label_path'=>$path,
        ]);

        $admin=\App\Models\User::where('role','admin')->where('status','active')->first();
        if($admin){
            $notifications->send(
                $admin,
                'return_requested',
                'Rücksendung angefordert',
                'Für Auftrag #'.$order->order_number.' wurde eine Rücksendung auf Kosten der Anbieterin angefordert. Frist: '.$return->fulfillment_due_at->format('d.m.Y H:i').' Uhr.',
                route('admin.orders.show',$order),
                ['order_id'=>$order->id,'return_request_id'=>$return->id]
            );
        }

        return back()->with('success',$data['method']==='own_label'
            ? 'Rücksendung wurde angefordert und das eigene Rücksendeetikett hinterlegt.'
            : 'Rücksendung wurde angefordert. Der Admin teilt dir die tatsächlichen Rücksendekosten mit; die Zahlung muss innerhalb der laufenden 24-Stunden-Frist erfolgen.');
    }
}
