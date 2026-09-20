<?php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CameraCaptureService;
use App\Services\V1OrderWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DamageController extends Controller
{
    public function store(Request $request, Order $order, CameraCaptureService $captures, V1OrderWorkflowService $workflow)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_unless($order->status==='active',422,'Beschädigungen können nur während eines laufenden Auftrags gemeldet werden.');

        $data=$request->validate([
            'reason'=>['required','string','max:2000'],
            'photo'=>['required','image','mimes:jpg,jpeg','max:10240'],
            'camera_capture_token'=>['required','string','max:128'],
        ]);

        abort_unless(
            str_starts_with(strtolower((string)$data['photo']->getClientOriginalName()),'live-'),
            422,
            'Das Beschädigungsfoto muss direkt über die Live-Kamera aufgenommen werden.'
        );

        $captures->consume(
            $request,
            $data['camera_capture_token'],
            'damage:'.$order->id.':'.$order->series_number
        );

        $path=$data['photo']->storeAs(
            $order->order_number.'/run-'.$order->series_number,
            Str::uuid().'.jpg',
            'damage'
        );

        $absolute=Storage::disk('damage')->path($path);
        $workflow->reportDamage($order,$request->user(),$data['reason'],[
            'storage_path'=>$path,
            'sha256'=>hash_file('sha256',$absolute),
            'mime_type'=>$data['photo']->getMimeType(),
            'file_size'=>$data['photo']->getSize(),
            'captured_at'=>now()->toIso8601String(),
        ]);

        return back()->with('success','Beschädigung wurde dokumentiert. Der Auftrag und seine Fristen laufen bis zur Adminentscheidung normal weiter.');
    }

    public function fulfillEvidence(Request $request, Order $order, int $damageCase, int $evidenceRequest, CameraCaptureService $captures)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        $case=\Illuminate\Support\Facades\DB::table('damage_cases')->where('id',$damageCase)->where('order_id',$order->id)->first();
        abort_unless($case,404);
        $item=\Illuminate\Support\Facades\DB::table('damage_evidence_requests')->where('id',$evidenceRequest)->where('damage_case_id',$damageCase)->first();
        abort_unless($item,404);
        abort_unless($item->status==='open',422,'Diese Nachforderung wurde bereits erledigt.');
        abort_if($item->due_at && now('Europe/Berlin')->greaterThan(\Carbon\CarbonImmutable::parse($item->due_at,'Europe/Berlin')->addHour()),422,'Die Nachforderungsfrist einschließlich Nachfrist ist abgelaufen.');

        $submission=['submitted_at'=>now()->toIso8601String()];
        if(in_array($item->type,['text','field'],true)){
            $data=$request->validate(['value'=>['required','string','max:5000']]);
            $submission['value']=$data['value'];
        } else {
            $rules=$item->type==='photo'
                ? ['required','image','mimes:jpg,jpeg','max:10240']
                : ['required','file','max:204800'];
            $data=$request->validate([
                'file'=>$rules,
                'camera_capture_token'=>['nullable','string','max:128'],
            ]);
            $file=$request->file('file');

            if($item->type==='photo'){
                abort_unless(str_starts_with(strtolower((string)$file->getClientOriginalName()),'live-'),422,'Foto-Nachforderungen müssen direkt über die Live-Kamera aufgenommen werden.');
                $captures->consume(
                    $request,
                    (string)($data['camera_capture_token']??''),
                    'damage-request:'.$order->id.':'.$damageCase.':'.$evidenceRequest
                );
            } else {
                abort_unless(str_starts_with((string)$file->getMimeType(),'video/'),422,'Bitte eine Videodatei einreichen.');
            }

            $extension=strtolower($file->getClientOriginalExtension() ?: ($item->type==='photo'?'jpg':'mp4'));
            $path=$file->storeAs($order->order_number.'/case-'.$damageCase,\Illuminate\Support\Str::uuid().'.'.$extension,'damage');
            $submission += [
                'storage_path'=>$path,
                'mime_type'=>$file->getMimeType(),
                'file_size'=>$file->getSize(),
                'sha256'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('damage')->path($path)),
            ];
        }

        \Illuminate\Support\Facades\DB::table('damage_evidence_requests')->where('id',$evidenceRequest)->update([
            'submission'=>json_encode($submission,JSON_UNESCAPED_UNICODE),
            'fulfilled_at'=>now(),
            'status'=>'fulfilled',
            'updated_at'=>now(),
        ]);
        \Illuminate\Support\Facades\DB::table('damage_cases')->where('id',$damageCase)->update(['status'=>'review','updated_at'=>now()]);

        return back()->with('success','Zusätzlicher Nachweis wurde dem Beschädigungsvorgang zugeordnet.');
    }
}
