<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class OfferController extends Controller {
    public function index(){ $offers=Offer::with('category')->latest()->paginate(30); return view('admin.offers.index',compact('offers')); }
    public function create(){ $categories=Category::where('active',true)->orderBy('name')->get(); return view('admin.offers.form',['offer'=>new Offer,'categories'=>$categories]); }
    public function store(Request $request){ $offer=Offer::create($this->validated($request)+['slug'=>Str::slug($request->title).'-'.Str::lower(Str::random(5))]); $this->syncOptions($request,$offer); return redirect()->route('admin.offers.edit',$offer)->with('success','Angebot wurde erstellt.'); }
    public function edit(Offer $offer){ $offer->load('options'); $categories=Category::where('active',true)->orderBy('name')->get(); return view('admin.offers.form',compact('offer','categories')); }
    public function update(Request $request, Offer $offer){ $offer->update($this->validated($request)); $this->syncOptions($request,$offer); return back()->with('success','Angebot wurde gespeichert.'); }
    private function validated(Request $request): array {
        $data = $request->validate([
            'category_id'=>['required','exists:categories,id'],'title'=>['required','string','max:180'],'short_description'=>['nullable','string','max:500'],'description'=>['nullable','string'],
            'base_compensation'=>['required','numeric','min:0'],'duration_days'=>['required','integer','min:1','max:365'],'minimum_minutes_per_day'=>['required','integer','min:0','max:1440'],
            'proofs_per_day'=>['required','integer','min:0','max:20'],'shipping_deadline_hours'=>['required','integer','min:1','max:720'],
            'rules_text'=>['nullable','string']
        ]);
        unset($data['rules_text']);
        $data['requires_precheck'] = $request->boolean('requires_precheck');
        $data['active'] = $request->boolean('active');
        $data['rules'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $request->input('rules_text','')))));
        return $data;
    }
    private function syncOptions(Request $request, Offer $offer): void {
        $rows=$request->input('options',[]); $keep=[];
        foreach($rows as $i=>$row){ if(empty(trim($row['name']??''))) continue; $opt=$offer->options()->updateOrCreate(['id'=>$row['id']??null],['name'=>$row['name'],'description'=>$row['description']??null,'price_delta'=>(float)($row['price_delta']??0),'extra_proofs_per_day'=>(int)($row['extra_proofs_per_day']??0),'extra_duration_days'=>(int)($row['extra_duration_days']??0),'active'=>true,'sort_order'=>$i]); $keep[]=$opt->id; }
        $offer->options()->whereNotIn('id',$keep ?: [0])->update(['active'=>false]);
    }
}
