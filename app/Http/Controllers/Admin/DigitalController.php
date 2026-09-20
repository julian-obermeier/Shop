<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\V1FinalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DigitalController extends Controller
{
    public function download(Order $order, int $version): BinaryFileResponse
    {
        $row=DB::table('digital_versions')
            ->join('digital_components','digital_components.id','=','digital_versions.digital_component_id')
            ->where('digital_versions.id',$version)
            ->where('digital_components.order_id',$order->id)
            ->select('digital_versions.*')
            ->first();
        abort_unless($row && $row->storage_path,404);
        abort_unless(Storage::disk('digital')->exists($row->storage_path),404);
        return Storage::disk('digital')->download($row->storage_path);
    }

    public function review(
        Request $request,
        Order $order,
        int $component,
        NotificationService $notifications,
        V1FinalizationService $finalization
    ) {
        $digital=DB::table('digital_components')->where('id',$component)->where('order_id',$order->id)->first();
        abort_unless($digital,404);

        $data=$request->validate([
            'decision'=>['required','in:accepted,revision,partial,rejected'],
            'amount'=>['nullable','numeric','min:0'],
            'reason'=>['nullable','string','max:3000','required_if:decision,revision,partial,rejected'],
            'revision_items_text'=>['nullable','string','max:10000'],
            'due_at'=>['nullable','date'],
        ]);

        if($data['decision']==='revision'){
            abort_if(
                DB::table('revision_rounds')->where('digital_component_id',$component)->whereIn('status',['open','submitted'])->exists(),
                422,
                'Es gibt bereits eine aktive Revisionsrunde.'
            );

            $roundNo=(int)DB::table('revision_rounds')->where('digital_component_id',$component)->max('round_no')+1;
            $roundId=DB::table('revision_rounds')->insertGetId([
                'digital_component_id'=>$component,
                'round_no'=>$roundNo,
                'status'=>'open',
                'due_at'=>$data['due_at']??now()->addDay(),
                'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            $items=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$data['revision_items_text']??$data['reason']))));
            foreach($items as $item){
                DB::table('revision_items')->insert([
                    'revision_round_id'=>$roundId,
                    'priority'=>'normal',
                    'description'=>$item,
                    'status'=>'open',
                    'due_at'=>$data['due_at']??null,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            DB::table('digital_components')->where('id',$component)->update(['status'=>'revision_required','updated_at'=>now()]);
            $order->update(['status'=>'active','phase'=>'execution']);
            $order->statusHistory()->create([
                'changed_by'=>$request->user()->id,
                'from_status'=>'digital_review',
                'to_status'=>'active',
                'reason'=>'Digitale Revision '.$roundNo.' angefordert: '.$data['reason'],
            ]);

            $notifications->send($order->user,'digital_revision','Digitale Revision erforderlich','Für Auftrag #'.$order->order_number.' wurde Revision '.$roundNo.' angefordert. '.$data['reason'],route('orders.show',$order));
            return back()->with('success','Revision wurde angefordert.');
        }

        $componentStatus=$data['decision']==='partial'?'partial':'accepted';
        if($data['decision']==='rejected'){
            DB::table('digital_components')->where('id',$component)->update(['status'=>'rejected','updated_at'=>now()]);
            $finalization->finalize($order,$request->user(),0,'rejected',$data['reason']);
            $notifications->send($order->user,'digital_rejected','Digitaler Auftrag abgelehnt','Auftrag #'.$order->order_number.' wurde endgültig abgelehnt. Grund: '.$data['reason'],route('orders.show',$order));
            return back()->with('success','Digitaler Auftrag wurde endgültig abgelehnt und archiviert.');
        }

        DB::table('digital_components')->where('id',$component)->update(['status'=>$componentStatus,'updated_at'=>now()]);
        DB::table('order_components')->where('id',$digital->order_component_id)->update(['status'=>$componentStatus,'updated_at'=>now()]);

        $hasPhysical=DB::table('order_components')->where('order_id',$order->id)->where('type','physical')->exists();
        if(!$hasPhysical){
            $amount=$data['decision']==='accepted'
                ? (float)$order->compensation_total
                : (float)($data['amount']??0);

            if($data['decision']==='partial'){
                abort_if($amount<=0 || $amount>=(float)$order->compensation_total,422,'Bei Teilannahme muss ein Teilbetrag größer als 0 und kleiner als der Gesamtwert angegeben werden.');
            }

            $finalization->finalize($order,$request->user(),$amount,$data['decision'],$data['reason']??null);
        }

        $notifications->send(
            $order->user,
            'digital_'.$data['decision'],
            $data['decision']==='accepted'?'Digitale Leistung akzeptiert':'Digitale Leistung teilweise akzeptiert',
            $data['decision']==='accepted'
                ? 'Die digitale Leistung zu Auftrag #'.$order->order_number.' wurde akzeptiert.'
                : 'Die digitale Leistung zu Auftrag #'.$order->order_number.' wurde teilweise akzeptiert. Grund: '.$data['reason'],
            route('orders.show',$order)
        );

        return back()->with('success','Digitale Prüfung wurde gespeichert.');
    }

    public function revisionItem(Request $request, int $item)
    {
        $row=DB::table('revision_items')->where('id',$item)->first();
        abort_unless($row,404);
        $data=$request->validate([
            'status'=>['required','in:done,insufficient,change_again'],
            'admin_comment'=>['nullable','string','max:2000'],
            'due_at'=>['nullable','date'],
        ]);
        DB::table('revision_items')->where('id',$item)->update([
            'status'=>$data['status'],
            'admin_comment'=>$data['admin_comment']??null,
            'due_at'=>$data['due_at']??$row->due_at,
            'updated_at'=>now(),
        ]);
        return back()->with('success','Revisionspunkt wurde aktualisiert.');
    }
}
