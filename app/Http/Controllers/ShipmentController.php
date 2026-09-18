<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShipmentController extends Controller
{
    public function store(Request $request, Order $order)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_if($request->user()->hasRestriction('uploads'),422,'Datei-Uploads sind für dieses Konto derzeit gesperrt.');

        $existing=$order->shipment;
        $isResubmission=$existing && $existing->review_status==='rejected' && $existing->resubmit_due_at?->isFuture();

        abort_unless(
            in_array($order->status,['waiting_shipping','shipping_overdue'],true) || ($order->status==='shipped' && $isResubmission),
            422,
            'Der Versandnachweis kann aktuell nicht eingereicht werden.'
        );

        $trackingMode=(string)data_get($order->offer_snapshot,'tracking_mode','optional');

        $data=$request->validate([
            'carrier'=>['required','string','max:100'],
            'tracking_number'=>[$trackingMode==='required'?'required':'nullable','string','max:150'],
            'package_photo'=>['required','image','mimes:jpg,jpeg,png,webp','max:10240'],
            'receipt_photo'=>['required','image','mimes:jpg,jpeg,png,webp','max:10240'],
        ]);

        if($trackingMode==='none') $data['tracking_number']=null;

        DB::transaction(function() use($order,$request,$data,$isResubmission){
            $fresh=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from=$fresh->status;

            $shipment=Shipment::where('order_id',$fresh->id)->lockForUpdate()->first();
            if(!$shipment){
                $shipment=Shipment::create([
                    'order_id'=>$fresh->id,
                    'carrier'=>$data['carrier'],
                    'tracking_number'=>$data['tracking_number']??null,
                    'status'=>'shipped',
                    'review_status'=>'pending',
                    'shipped_at'=>now(),
                    'ownership_transferred_at'=>now(),
                ]);
            } else {
                $shipment->update([
                    'carrier'=>$data['carrier'],
                    'tracking_number'=>$data['tracking_number']??null,
                    'status'=>'shipped',
                    'review_status'=>'pending',
                    'review_comment'=>null,
                    'resubmit_due_at'=>null,
                ]);
            }

            $attempt=(int)$shipment->evidences()->max('attempt');
            if($isResubmission) $attempt++;

            foreach(['package_photo'=>'package','receipt_photo'=>'receipt'] as $field=>$type){
                $file=$data[$field];
                $extension=strtolower($file->getClientOriginalExtension() ?: 'jpg');
                $path=$file->storeAs($fresh->order_number.'/attempt-'.$attempt,Str::uuid().'.'.$extension,'shipments');
                $absolute=Storage::disk('shipments')->path($path);

                $shipment->evidences()->create([
                    'user_id'=>$request->user()->id,
                    'type'=>$type,
                    'storage_path'=>$path,
                    'original_name'=>$file->getClientOriginalName(),
                    'mime_type'=>$file->getMimeType() ?: 'image/jpeg',
                    'file_size'=>$file->getSize(),
                    'sha256'=>hash_file('sha256',$absolute),
                    'attempt'=>$attempt,
                ]);

                if($type==='package'){
                    $shipment->update(['package_photo_path'=>$path,'package_photo_sha256'=>hash_file('sha256',$absolute)]);
                } else {
                    $shipment->update(['receipt_photo_path'=>$path,'receipt_photo_sha256'=>hash_file('sha256',$absolute)]);
                }
            }

            if($from!=='shipped'){
                $fresh->update(['status'=>'shipped']);
                $fresh->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>$from,
                    'to_status'=>'shipped',
                    'reason'=>$from==='shipping_overdue'
                        ? 'Versand nach Fristüberschreitung mit Paketfoto und Versandbeleg gemeldet'
                        : 'Versand mit Paketfoto und Versandbeleg gemeldet',
                ]);
            } else {
                $fresh->statusHistory()->create([
                    'changed_by'=>$request->user()->id,
                    'from_status'=>'shipped',
                    'to_status'=>'shipped',
                    'reason'=>'Versandnachweis innerhalb der Nachreichfrist erneut aufgenommen',
                ]);
            }
        });

        return back()->with('success',$isResubmission
            ? 'Neue Versandnachweise wurden eingereicht.'
            : 'Versand und Nachweise wurden gespeichert. Die Ware muss die Auftragsnummer im Paket enthalten.');
    }
}
