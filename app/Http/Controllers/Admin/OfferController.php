<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function index(){ $offers=Offer::with('category')->latest()->paginate(30); return view('admin.offers.index',compact('offers')); }

    public function create()
    {
        $categories=Category::where('active',true)->orderBy('name')->get();
        return view('admin.offers.form',['offer'=>new Offer,'categories'=>$categories,'optionRows'=>[]]);
    }

    public function store(Request $request, AuditService $audit)
    {
        $offer=Offer::create($this->validated($request)+['slug'=>Str::slug($request->title).'-'.Str::lower(Str::random(5))]);
        $this->syncOptions($request,$offer);
        $audit->log('offer.created',$offer,[],$offer->fresh()->toArray());
        return redirect()->route('admin.offers.edit',$offer)->with('success','Angebot wurde erstellt.');
    }

    public function edit(Offer $offer)
    {
        $offer->load('options');
        $categories=Category::where('active',true)->orderBy('name')->get();
        $map=$offer->options->keyBy('id');
        $optionRows=$offer->options->map(function($option) use($map){
            $row=$option->toArray();
            $rules=$option->rules?:[];
            $row['requires_names']=collect($rules['requires_ids']??[])->map(fn($id)=>$map->get((int)$id)?->name)->filter()->implode(', ');
            $row['excludes_names']=collect($rules['excludes_ids']??[])->map(fn($id)=>$map->get((int)$id)?->name)->filter()->implode(', ');
            $row['min_duration_days']=(int)($rules['min_duration_days']??0);
            return $row;
        })->values()->all();
        return view('admin.offers.form',compact('offer','categories','optionRows'));
    }

    public function update(Request $request, Offer $offer, AuditService $audit)
    {
        $before=$offer->toArray();
        $offer->update($this->validated($request));
        $this->syncOptions($request,$offer);
        $audit->log('offer.updated',$offer,$before,$offer->fresh()->toArray());
        return back()->with('success','Angebot wurde gespeichert.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'category_id'=>['required','exists:categories,id'],
            'title'=>['required','string','max:180'],
            'short_description'=>['nullable','string','max:500'],
            'description'=>['nullable','string'],
            'base_compensation'=>['required','numeric','min:0'],
            'duration_days'=>['required','integer','min:1','max:365'],
            'minimum_minutes_per_day'=>['required','integer','min:0','max:1440'],
            'proofs_per_day'=>['required','integer','min:0','max:20'],
            'shipping_deadline_hours'=>['required','integer','min:1','max:720'],
            'rules_text'=>['nullable','string'],
        ]);
        unset($data['rules_text']);
        $data['requires_precheck']=$request->boolean('requires_precheck');
        $data['active']=$request->boolean('active');
        $data['rules']=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$request->input('rules_text','')))));
        return $data;
    }

    private function syncOptions(Request $request, Offer $offer): void
    {
        $request->validate([
            'options'=>['nullable','array','max:30'],
            'options.*.name'=>['nullable','string','max:120'],
            'options.*.description'=>['nullable','string','max:500'],
            'options.*.price_delta'=>['nullable','numeric','min:0','max:10000'],
            'options.*.extra_proofs_per_day'=>['nullable','integer','min:0','max:20'],
            'options.*.extra_duration_days'=>['nullable','integer','min:0','max:365'],
            'options.*.min_duration_days'=>['nullable','integer','min:0','max:365'],
            'options.*.requires_names'=>['nullable','string','max:500'],
            'options.*.excludes_names'=>['nullable','string','max:500'],
        ]);

        $rows=$request->input('options',[]);
        $keep=[]; $saved=[];

        foreach($rows as $i=>$row){
            $name=trim($row['name']??'');
            if($name==='') continue;
            $opt=$offer->options()->updateOrCreate(
                ['id'=>$row['id']??null],
                [
                    'name'=>$name,
                    'description'=>$row['description']??null,
                    'price_delta'=>(float)($row['price_delta']??0),
                    'extra_proofs_per_day'=>(int)($row['extra_proofs_per_day']??0),
                    'extra_duration_days'=>(int)($row['extra_duration_days']??0),
                    'required'=>filter_var($row['required']??false,FILTER_VALIDATE_BOOLEAN),
                    'active'=>true,
                    'sort_order'=>$i,
                ]
            );
            $keep[]=$opt->id;
            $saved[$i]=$opt;
        }

        $offer->options()->whereNotIn('id',$keep?:[0])->update(['active'=>false]);
        $nameMap=$offer->options()->where('active',true)->get()->mapWithKeys(fn($o)=>[mb_strtolower(trim($o->name))=>$o->id]);

        foreach($saved as $i=>$opt){
            $row=$rows[$i];
            $parse=function(?string $value) use($nameMap,$opt){
                return collect(explode(',',$value??''))
                    ->map(fn($name)=>mb_strtolower(trim($name)))
                    ->filter()
                    ->map(fn($name)=>$nameMap->get($name))
                    ->filter(fn($id)=>$id && (int)$id!==$opt->id)
                    ->map(fn($id)=>(int)$id)
                    ->unique()->values()->all();
            };
            $opt->update(['rules'=>[
                'requires_ids'=>$parse($row['requires_names']??''),
                'excludes_ids'=>$parse($row['excludes_names']??''),
                'min_duration_days'=>(int)($row['min_duration_days']??0),
            ]]);
        }
    }
}
