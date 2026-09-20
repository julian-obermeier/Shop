<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        $categories=Category::with(['parent','fields'])->withCount('offers')->orderBy('sort_order')->orderBy('name')->get();
        $parents=Category::where('kind','group')->orderBy('sort_order')->get();
        return view('admin.categories.index',compact('categories','parents'));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120'],
            'icon'=>['nullable','string','max:20'],
            'parent_id'=>['nullable','exists:categories,id'],
            'kind'=>['required','in:group,physical,digital,special'],
            'sort_order'=>['nullable','integer','min:0'],
        ]);
        Category::create([
            'parent_id'=>$data['parent_id']??null,
            'name'=>$data['name'],
            'slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'icon'=>$data['icon']??null,
            'kind'=>$data['kind'],
            'sort_order'=>(int)($data['sort_order']??0),
            'system_template'=>false,
            'active'=>$request->boolean('active',true),
            'config'=>['third_party_goods_allowed'=>false,'seller_must_perform_personally'=>true],
        ]);
        return back()->with('success','Kategorie wurde angelegt.');
    }

    public function update(Request $request, Category $category)
    {
        $data=$request->validate([
            'name'=>['required','string','max:120'],
            'icon'=>['nullable','string','max:20'],
            'parent_id'=>['nullable','exists:categories,id'],
            'kind'=>['required','in:group,physical,digital,special'],
            'sort_order'=>['nullable','integer','min:0'],
        ]);
        abort_if((int)($data['parent_id']??0)===$category->id,422,'Eine Kategorie kann nicht ihr eigener Elternknoten sein.');
        $category->update([
            'parent_id'=>$data['parent_id']??null,
            'name'=>$data['name'],
            'icon'=>$data['icon']??null,
            'kind'=>$data['kind'],
            'sort_order'=>(int)($data['sort_order']??0),
            'active'=>$request->boolean('active'),
        ]);
        return back()->with('success','Kategorie wurde gespeichert.');
    }

    public function destroy(Category $category)
    {
        abort_if($category->offers()->whereHas('orders')->exists(),422,'Die Kategorie ist mit historischen Aufträgen verknüpft und kann nicht gelöscht werden.');
        abort_if($category->children()->exists(),422,'Bitte verschiebe oder lösche zuerst die Unterkategorien.');
        $category->delete();
        return back()->with('success','Unbenutzte Kategorie wurde gelöscht.');
    }

    public function storeField(Request $request, Category $category)
    {
        $data=$request->validate([
            'label'=>['required','string','max:160'],
            'type'=>['required','in:text,textarea,number,select,multiselect,boolean,date'],
            'options_text'=>['nullable','string','max:3000'],
        ]);
        $key=Str::slug($data['label'],'_') ?: 'feld_'.($category->fields()->count()+1);
        $base=$key; $i=2; while($category->fields()->where('key',$key)->exists()) $key=$base.'_'.$i++;
        $options=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$data['options_text']??''))));
        $category->fields()->create([
            'key'=>$key,'label'=>$data['label'],'type'=>$data['type'],'options'=>$options?:null,
            'required'=>$request->boolean('required'),'active'=>true,'sort_order'=>$category->fields()->count()*10,
        ]);
        return back()->with('success','Kategoriefeld wurde angelegt.');
    }

    public function updateField(Request $request, Category $category, int $field)
    {
        $row=$category->fields()->findOrFail($field);
        $data=$request->validate([
            'label'=>['required','string','max:160'],
            'type'=>['required','in:text,textarea,number,select,multiselect,boolean,date'],
            'options_text'=>['nullable','string','max:3000'],
            'sort_order'=>['nullable','integer','min:0'],
        ]);
        $options=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$data['options_text']??''))));
        $row->update([
            'label'=>$data['label'],'type'=>$data['type'],'options'=>$options?:null,
            'required'=>$request->boolean('required'),'active'=>$request->boolean('active'),
            'sort_order'=>(int)($data['sort_order']??$row->sort_order),
        ]);
        return back()->with('success','Kategoriefeld wurde gespeichert.');
    }
}
