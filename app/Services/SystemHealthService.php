<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemHealthService
{
    public function check(): array
    {
        $checks=[];

        try{
            DB::select('SELECT 1');
            $checks[]=['name'=>'Datenbank','status'=>'ok','detail'=>'Verbindung erfolgreich'];
        }catch(\Throwable $e){
            $checks[]=['name'=>'Datenbank','status'=>'error','detail'=>$e->getMessage()];
        }

        foreach(['proofs','prechecks','messages','shipments','returns','public'] as $disk){
            try{
                $path='health/'.Str::uuid().'.txt';
                Storage::disk($disk)->put($path,'ok');
                $read=Storage::disk($disk)->get($path);
                Storage::disk($disk)->delete($path);
                $checks[]=[
                    'name'=>'Storage: '.$disk,
                    'status'=>$read==='ok'?'ok':'error',
                    'detail'=>$read==='ok'?'Schreiben/Lesen/Löschen erfolgreich':'Lesetest fehlgeschlagen',
                ];
            }catch(\Throwable $e){
                $checks[]=['name'=>'Storage: '.$disk,'status'=>'error','detail'=>$e->getMessage()];
            }
        }

        $checks[]=['name'=>'Queue','status'=>'info','detail'=>'Treiber: '.config('queue.default')];
        $checks[]=['name'=>'Mail','status'=>'info','detail'=>'Mailer: '.config('mail.default')];
        $checks[]=['name'=>'Umgebung','status'=>'info','detail'=>app()->environment().' · PHP '.PHP_VERSION];

        return [
            'ok'=>collect($checks)->where('status','error')->isEmpty(),
            'checked_at'=>now(),
            'checks'=>$checks,
        ];
    }
}
