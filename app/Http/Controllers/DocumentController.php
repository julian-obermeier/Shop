<?php
namespace App\Http\Controllers;

use App\Models\Document;

class DocumentController extends Controller
{
    public function index()
    {
        $documents=Document::where('active',true)
            ->with(['versions'=>fn($q)=>$q->where('active',true)->whereNotNull('published_at')->latest('published_at')])
            ->orderBy('title')
            ->get();

        $consents=request()->user()
            ->documentConsents()
            ->with('version')
            ->latest('consented_at')
            ->get()
            ->keyBy('document_version_id');

        return view('documents.index',compact('documents','consents'));
    }
}
