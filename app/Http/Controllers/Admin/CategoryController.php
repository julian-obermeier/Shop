<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        $categories=Category::withCount('offers')->orderBy('name')->get();
        return view('admin.categories.index',compact('categories'));
    }

    public function store(Request $request, AuditService $audit)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120'],
            'icon'=>['nullable','string','max:20'],
        ]);
        $category=Category::create([
            'name'=>$data['name'],
            'slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'icon'=>$data['icon']??null,
            'active'=>$request->boolean('active',true),
        ]);
        $audit->log('category.created',$category,[],$category->toArray());
        return back()->with('success','Kategorie wurde angelegt.');
    }

    public function update(Request $request, Category $category, AuditService $audit)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120'],
            'icon'=>['nullable','string','max:20'],
        ]);
        $before=$category->toArray();
        $category->update([
            'name'=>$data['name'],
            'icon'=>$data['icon']??null,
            'active'=>$request->boolean('active'),
        ]);
        $audit->log('category.updated',$category,$before,$category->fresh()->toArray());
        return back()->with('success','Kategorie wurde gespeichert.');
    }
}
