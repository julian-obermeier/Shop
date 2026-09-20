<?php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DigitalSubmissionController extends Controller
{
    public function store(Request $request, Order $order, int $component)
    {
        abort_unless($order->user_id===$request->user()->id,403);
        abort_if($order->isTerminal(),422,'Dieser Auftrag ist bereits abgeschlossen.');

        $digital=DB::table('digital_components')->where('id',$component)->where('order_id',$order->id)->first();
        abort_unless($digital,404);
        $requirements=json_decode($digital->requirements ?: '{}',true) ?: [];
        $allowed=$requirements['formats']??['text','audio','video'];

        $data=$request->validate([
            'submission_type'=>['required','in:text,audio,video'],
            'text_content'=>['nullable','string'],
            'file'=>['nullable','file','max:204800'],
            'final_submission'=>['nullable','boolean'],
        ]);

        abort_unless(in_array($data['submission_type'],$allowed,true),422,'Dieses Abgabeformat ist für den Auftrag nicht freigegeben.');

        $text=null; $path=null; $mime=null; $size=null; $sha=null; $meta=['optimized_playback'=>false];
        $technical=$requirements['technical']??[];

        if($data['submission_type']==='text'){
            $text=trim((string)($data['text_content']??''));
            abort_if($text==='',422,'Bitte den Textinhalt eingeben.');
            $length=mb_strlen($text);
            if(isset($technical['min_length'])) abort_if($length<(int)$technical['min_length'],422,'Der Text ist kürzer als die geforderte Mindestlänge.');
            if(isset($technical['max_length'])) abort_if($length>(int)$technical['max_length'],422,'Der Text überschreitet die zulässige Maximallänge.');
            $meta['characters']=$length;
        } else {
            abort_unless($request->hasFile('file'),422,'Bitte eine Datei auswählen.');
            $file=$request->file('file');
            $mime=(string)($file->getMimeType() ?: 'application/octet-stream');
            $isAudio=str_starts_with($mime,'audio/');
            $isVideo=str_starts_with($mime,'video/');
            abort_if($data['submission_type']==='audio' && !$isAudio,422,'Die Datei ist kein unterstütztes Audioformat.');
            abort_if($data['submission_type']==='video' && !$isVideo,422,'Die Datei ist kein unterstütztes Videoformat.');

            $size=(int)$file->getSize();
            if(isset($technical['max_bytes'])) abort_if($size>(int)$technical['max_bytes'],422,'Die Datei überschreitet die zulässige Maximalgröße.');

            $extension=strtolower($file->getClientOriginalExtension() ?: ($isVideo?'mp4':'bin'));
            $path=$file->storeAs($order->order_number.'/component-'.$component,Str::uuid().'.'.$extension,'digital');
            $absolute=Storage::disk('digital')->path($path);
            $sha=hash_file('sha256',$absolute);
            $meta['original_name']=$file->getClientOriginalName();
            $meta['fallback_playback_original']=true;
        }

        $versionNo=DB::transaction(function() use($digital,$data,$text,$path,$mime,$size,$sha,$meta,$order){
            $current=(int)DB::table('digital_versions')->where('digital_component_id',$digital->id)->lockForUpdate()->max('version_no');
            $version=$current+1;

            DB::table('digital_versions')->insert([
                'digital_component_id'=>$digital->id,
                'version_no'=>$version,
                'submission_type'=>$data['submission_type'],
                'text_content'=>$text,
                'storage_path'=>$path,
                'playback_path'=>$path,
                'mime_type'=>$mime,
                'file_size'=>$size,
                'sha256'=>$sha,
                'technical_meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE),
                'final_submission'=>(bool)($data['final_submission']??false),
                'submitted_at'=>now(),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            if(!empty($data['final_submission'])){
                DB::table('digital_components')->where('id',$digital->id)->update(['status'=>'submitted','updated_at'=>now()]);
                DB::table('revision_rounds')
                    ->where('digital_component_id',$digital->id)
                    ->where('status','open')
                    ->update(['status'=>'submitted','updated_at'=>now()]);
                $order->update(['status'=>'digital_review','phase'=>'review']);
                $order->statusHistory()->create([
                    'changed_by'=>auth()->id(),
                    'from_status'=>$order->getOriginal('status'),
                    'to_status'=>'digital_review',
                    'reason'=>'Digitale Version V'.$version.' final eingereicht',
                ]);
            }

            return $version;
        });

        return back()->with('success','Digitale Version V'.$versionNo.' wurde unveränderlich gespeichert.'.($request->boolean('final_submission')?' Sie liegt jetzt zur Prüfung vor.':''));
    }

    public function stream(Request $request, Order $order, int $version): Response
    {
        abort_unless($order->user_id===$request->user()->id,403);
        $row=DB::table('digital_versions')
            ->join('digital_components','digital_components.id','=','digital_versions.digital_component_id')
            ->where('digital_versions.id',$version)
            ->where('digital_components.order_id',$order->id)
            ->select('digital_versions.*')
            ->first();
        abort_unless($row && $row->storage_path,404);
        abort_unless(Storage::disk('digital')->exists($row->storage_path),404);

        return Storage::disk('digital')->response($row->storage_path,null,[
            'Content-Type'=>$row->mime_type ?: 'application/octet-stream',
            'Content-Disposition'=>'inline',
            'X-Content-Type-Options'=>'nosniff',
            'Cache-Control'=>'private, no-store',
        ]);
    }
}
