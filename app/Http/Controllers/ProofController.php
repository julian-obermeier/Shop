<?php
namespace App\Http\Controllers;

use App\Models\OrderDay;
use App\Services\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProofController extends Controller
{
    public function store(Request $request, OrderDay $day, ImageSanitizer $images)
    {
        abort_if($request->user()->hasRestriction('uploads'),422,'Uploads sind für dieses Konto derzeit gesperrt.');
        abort_unless($day->order()->where('user_id',$request->user()->id)->exists(),403);

        $data=$request->validate([
            'proof'=>['required','image','mimes:jpg,jpeg,png,webp','max:10240'],
        ]);

        $file=$data['proof'];
        $stored=$images->store($file,'proofs',$day->order_id.'/'.$day->day_number,2200,2200);
        $absolute=Storage::disk('proofs')->path($stored['path']);

        $day->proofs()->create([
            'user_id'=>$request->user()->id,
            'storage_path'=>$stored['path'],
            'original_name'=>$file->getClientOriginalName(),
            'mime_type'=>$stored['mime'],
            'file_size'=>$stored['size'],
            'sha256'=>hash_file('sha256',$absolute),
            'review_status'=>'pending',
        ]);

        return back()->with('success','Nachweis wurde sicher hochgeladen und von Bildmetadaten bereinigt.');
    }
}
