<?php
namespace App\Http\Controllers;
use App\Models\OrderDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class ProofController extends Controller {
    public function store(Request $request, OrderDay $day){
        abort_if($request->user()->hasRestriction('uploads'),422,'Uploads sind für dieses Konto derzeit gesperrt.');
        abort_unless($day->order()->where('user_id',$request->user()->id)->exists(),403);
        $data=$request->validate(['proof'=>['required','file','mimes:jpg,jpeg,png,webp','max:10240']]);
        $file=$data['proof'];
        $extension=strtolower($file->getClientOriginalExtension());
        $name=(string)Str::uuid().'.'.$extension;
        $folder=$day->order_id.'/'.$day->day_number;
        $path=$file->storeAs($folder,$name,'proofs');
        $absolute=Storage::disk('proofs')->path($path);
        $day->proofs()->create([
            'user_id'=>$request->user()->id,
            'storage_path'=>$path,
            'original_name'=>$file->getClientOriginalName(),
            'mime_type'=>$file->getMimeType(),
            'file_size'=>$file->getSize(),
            'sha256'=>hash_file('sha256',$absolute),
            'review_status'=>'pending',
        ]);
        return back()->with('success','Nachweis wurde sicher hochgeladen.');
    }
}
