<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\ProofSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
class ProofController extends Controller {
    public function index(){ $proofs=ProofSubmission::with('orderDay.order.user')->where('review_status','pending')->latest()->paginate(40); return view('admin.proofs.index',compact('proofs')); }
    public function file(ProofSubmission $proof): StreamedResponse { return Storage::disk('proofs')->download($proof->storage_path,$proof->original_name); }
    public function review(Request $request, ProofSubmission $proof){ $data=$request->validate(['review_status'=>['required','in:accepted,rejected,resubmit'],'review_comment'=>['nullable','string','max:1000']]); $proof->update($data+['reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]); return back()->with('success','Nachweis wurde geprüft.'); }
}
