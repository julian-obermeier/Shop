<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CameraCaptureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrecheckController extends Controller
{
    public function details(Request $request, Order $order)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Für diesen Auftrag ist aktuell keine Vorabkontrolle möglich.');

        $data=$request->validate([
            'item_description'=>['required','string','max:2000'],
            'item_size'=>['nullable','string','max:100'],
            'item_type'=>['nullable','string','max:150'],
        ]);

        $precheck=$order->precheck()->firstOrNew(['user_id'=>$request->user()->id]);
        $precheck->fill([
            'user_id'=>$request->user()->id,
            'status'=>'draft',
            'item_description'=>$data['item_description'],
            'item_size'=>$data['item_size']??null,
            'item_type'=>$data['item_type']??null,
            'submitted_at'=>null,
            'admin_comment'=>null,
            'reviewed_by'=>null,
            'reviewed_at'=>null,
        ])->save();

        return back()->with('success','Artikeldaten wurden gespeichert. Nimm jetzt die erforderlichen Vorabfotos auf.');
    }

    public function evidence(Request $request, Order $order, CameraCaptureService $captures)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Für diesen Auftrag ist aktuell keine Vorabkontrolle möglich.');

        $slots=$this->slots($order);
        $allowed=array_column($slots,'key');

        $data=$request->validate([
            'slot_key'=>['required','string','in:'.implode(',',$allowed)],
            'photo'=>['required','image','mimes:jpg,jpeg','max:10240'],
            'camera_capture_token'=>['required','string','max:128'],
        ]);

        $slot=collect($slots)->firstWhere('key',$data['slot_key']);
        $runId=DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$order->series_number)->value('id');

        $latest=DB::table('order_precheck_evidences')
            ->where('order_id',$order->id)
            ->where('order_run_id',$runId)
            ->where('slot_key',$data['slot_key'])
            ->orderByDesc('id')
            ->first();

        abort_if($latest && $latest->status==='accepted',422,'Dieser Vorabnachweis wurde bereits akzeptiert und muss nicht erneut aufgenommen werden.');

        $original=(string)$data['photo']->getClientOriginalName();
        abort_unless(str_starts_with(strtolower($original),'live-'),422,'Vorabnachweise müssen direkt über die Live-Kamera aufgenommen werden.');

        $captures->consume(
            $request,
            $data['camera_capture_token'],
            'precheck:'.$order->id.':'.$order->series_number.':'.$data['slot_key']
        );

        $precheck=$order->precheck()->firstOrCreate(
            ['user_id'=>$request->user()->id],
            ['status'=>'draft']
        );

        $file=$data['photo'];
        $path=$file->storeAs(
            $order->order_number.'/run-'.$order->series_number,
            Str::uuid().'.jpg',
            'prechecks'
        );
        $absolute=Storage::disk('prechecks')->path($path);

        DB::table('order_precheck_evidences')->insert([
            'order_id'=>$order->id,
            'order_run_id'=>$runId,
            'order_precheck_id'=>$precheck->id,
            'slot_key'=>$data['slot_key'],
            'label'=>$slot['label']??$data['slot_key'],
            'storage_path'=>$path,
            'mime_type'=>$file->getMimeType() ?: 'image/jpeg',
            'file_size'=>$file->getSize(),
            'sha256'=>hash_file('sha256',$absolute),
            'status'=>'submitted',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        $precheck->update(['status'=>'draft','submitted_at'=>null]);

        return back()->with('success','Vorabfoto „'.($slot['label']??$data['slot_key']).'“ wurde unveränderlich gespeichert.');
    }

    public function submit(Request $request, Order $order)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Für diesen Auftrag ist aktuell keine Vorabkontrolle möglich.');

        $precheck=$order->precheck;
        abort_unless($precheck && trim((string)$precheck->item_description)!=='',422,'Bitte speichere zuerst die Artikeldaten.');

        $runId=DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$order->series_number)->value('id');
        $latest=$this->latestEvidenceBySlot($order,$runId);
        $missing=collect($this->slots($order))->filter(fn($slot)=>!isset($latest[$slot['key']]));

        abort_if($missing->isNotEmpty(),422,'Es fehlen noch Vorabfotos: '.$missing->pluck('label')->implode(', '));

        $precheck->update(['status'=>'submitted','submitted_at'=>now()]);
        $from=$order->status;
        $order->update(['status'=>'precheck']);
        $order->statusHistory()->create([
            'changed_by'=>$request->user()->id,
            'from_status'=>$from,
            'to_status'=>'precheck',
            'reason'=>'Vollständige Vorabkontrolle zur Einzelprüfung eingereicht',
        ]);

        return back()->with('success','Die vollständige Vorabkontrolle wurde zur Prüfung eingereicht.');
    }

    private function slots(Order $order): array
    {
        $slots=data_get($order->offer_snapshot,'category_config.precheck_slots',[]);
        if(!is_array($slots) || count($slots)===0){
            $slots=[['key'=>'item','label'=>'Konkreter Artikel']];
        }
        return array_values($slots);
    }

    private function latestEvidenceBySlot(Order $order, $runId): array
    {
        return DB::table('order_precheck_evidences')
            ->where('order_id',$order->id)
            ->where('order_run_id',$runId)
            ->orderByDesc('id')
            ->get()
            ->unique('slot_key')
            ->keyBy('slot_key')
            ->all();
    }
}
