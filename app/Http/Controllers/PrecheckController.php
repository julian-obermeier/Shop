<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrecheckController extends Controller
{
    public function store(Request $request, Order $order, ImageSanitizer $images)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_if($request->user()->hasRestriction('uploads'),422,'Uploads sind für dieses Konto derzeit gesperrt.');
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Für diesen Auftrag ist aktuell keine Vorprüfung möglich.');

        $data=$request->validate([
            'item_description'=>['required','string','max:2000'],
            'item_size'=>['nullable','string','max:100'],
            'item_type'=>['nullable','string','max:150'],
            'photo'=>['required_without:existing_photo','image','mimes:jpg,jpeg,png,webp','max:10240'],
        ]);

        $precheck=$order->precheck()->firstOrNew(['user_id'=>$request->user()->id]);

        if($request->hasFile('photo')){
            $old=$precheck->photo_path;
            $stored=$images->store($request->file('photo'),'prechecks',$order->order_number,2200,2200);
            $precheck->photo_path=$stored['path'];
            if($old && $old!==$stored['path']) Storage::disk('prechecks')->delete($old);
        }

        $precheck->fill([
            'user_id'=>$request->user()->id,
            'status'=>'submitted',
            'item_description'=>$data['item_description'],
            'item_size'=>$data['item_size']??null,
            'item_type'=>$data['item_type']??null,
            'submitted_at'=>now(),
            'admin_comment'=>null,
            'reviewed_by'=>null,
            'reviewed_at'=>null,
        ])->save();

        if($order->status!=='precheck'){
            $from=$order->status;
            $order->update(['status'=>'precheck']);
            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>$from,
                'to_status'=>'precheck',
                'reason'=>'Vorprüfung erneut eingereicht',
            ]);
        }

        return back()->with('success','Die Vorprüfung wurde eingereicht.');
    }
}
