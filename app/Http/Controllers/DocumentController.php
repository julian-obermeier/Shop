<?php
namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentConsent;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index()
    {
        $documents = Document::where('active',true)->with(['versions'=>fn($q)=>$q->where('active',true)->whereNotNull('published_at')->latest('published_at')])->orderBy('title')->get();
        $consented = request()->user()->documentConsents()->pluck('document_version_id')->all();
        return view('documents.index', compact('documents','consented'));
    }

    public function consent(Request $request, DocumentVersion $version)
    {
        abort_unless($version->active && $version->published_at, 404);
        DocumentConsent::firstOrCreate(
            ['document_version_id'=>$version->id,'user_id'=>$request->user()->id],
            ['ip_address'=>$request->ip(),'consented_at'=>now()]
        );
        return back()->with('success','Zustimmung wurde dokumentiert.');
    }
}
