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
}
