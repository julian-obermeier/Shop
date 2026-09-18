<?php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrecheckController extends Controller
{
    public function store(Request $request, Order $order)
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
        $answers=is_array($precheck->answers)?$precheck->answers:[];

        if($request->hasFile('photo')){
            if($precheck->photo_path){
                $answers['photo_history'][]=[
                    'path'=>$precheck->photo_path,
                    'replaced_at'=>now()->toIso8601String(),
                ];
            }

            $file=$request->file('photo');
            $extension=strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $path=$file->storeAs($order->order_number,Str::uuid().'.'.$extension,'prechecks');
            $precheck->photo_path=$path;
            $answers['current_photo_sha256']=hash_file('sha256',Storage::disk('prechecks')->path($path));
        }

        $precheck->fill([
            'user_id'=>$request->user()->id,
            'status'=>'submitted',
            'item_description'=>$data['item_description'],
            'item_size'=>$data['item_size']??null,
            'item_type'=>$data['item_type']??null,
            'answers'=>$answers,
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
