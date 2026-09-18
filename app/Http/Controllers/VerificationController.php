<?php
namespace App\Http\Controllers;

use App\Models\IdentityVerification;
use App\Services\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function index()
    {
        $verification=request()->user()->verifications()->latest()->first();
        return view('verification.index',compact('verification'));
    }

    public function store(Request $request, ImageSanitizer $images)
    {
        abort_if($request->user()->verified_at,422,'Das Konto ist bereits verifiziert.');
        abort_if($request->user()->hasRestriction('uploads'),422,'Uploads sind für dieses Konto derzeit gesperrt.');
        abort_if($request->user()->verifications()->whereIn('status',['requested','review'])->exists(),422,'Es ist bereits eine Prüfung offen.');

        $data=$request->validate([
            'document_front'=>['required','file','mimes:jpg,jpeg,png,webp,pdf','max:10240'],
            'document_back'=>['nullable','file','mimes:jpg,jpeg,png,webp,pdf','max:10240'],
        ]);

        $folder=$request->user()->id.'/'.Str::uuid();
        $front=$this->storeDocument($data['document_front'],$folder,$images);
        $back=isset($data['document_back'])?$this->storeDocument($data['document_back'],$folder,$images):null;

        IdentityVerification::create([
            'user_id'=>$request->user()->id,
            'status'=>'requested',
            'method'=>'manual',
            'document_front_path'=>$front,
            'document_back_path'=>$back,
        ]);

        return back()->with('success','Die Verifizierung wurde zur Prüfung eingereicht.');
    }

    private function storeDocument($file,string $folder,ImageSanitizer $images): string
    {
        $mime=$file->getMimeType();

        if(str_starts_with((string)$mime,'image/')){
            return $images->store($file,'identity',$folder,2600,2600)['path'];
        }

        return $file->storeAs($folder,Str::uuid().'.pdf','identity');
    }
}
