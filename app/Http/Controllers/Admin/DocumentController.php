<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index()
    {
        $documents=Document::with('versions')->orderBy('title')->get();
        return view('admin.documents.index',compact('documents'));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'title'=>['required','string','max:180'],
            'type'=>['required','string','max:30'],
            'version'=>['required','string','max:30'],
            'content'=>['required','string'],
        ]);
        $document=Document::firstOrCreate(
            ['key'=>Str::slug($data['title'])],
            ['title'=>$data['title'],'type'=>$data['type'],'requires_consent'=>$request->boolean('requires_consent'),'active'=>true]
        );
        $document->update(['title'=>$data['title'],'type'=>$data['type'],'requires_consent'=>$request->boolean('requires_consent'),'active'=>true]);
        $document->versions()->update(['active'=>false]);
        $document->versions()->create([
            'version'=>$data['version'],
            'content'=>$data['content'],
            'published_at'=>now(),
            'active'=>true,
        ]);
        return back()->with('success','Dokumentversion wurde veröffentlicht.');
    }
}
