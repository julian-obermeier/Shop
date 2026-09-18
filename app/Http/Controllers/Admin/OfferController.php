<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use App\Services\AuditService;
use App\Services\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function index()
    {
        $offers=Offer::with('category')->latest()->paginate(30);
        return view('admin.offers.index',compact('offers'));
    }

    public function create()
    {
        $categories=Category::where('active',true)->orderBy('name')->get();
        return view('admin.offers.form',[
            'offer'=>new Offer,
            'categories'=>$categories,
            'optionRows'=>[],
            'fieldRows'=>[],
        ]);
    }

    public function store(Request $request, AuditService $audit, ImageSanitizer $images)
    {
        $offer=Offer::create($this->validated($request)+[
            'slug'=>Str::slug($request->title).'-'.Str::lower(Str::random(5)),
        ]);

        $this->handleImage($request,$offer,$images);
        $this->syncOptions($request,$offer);
        $this->syncFields($request,$offer);

        $audit->log('offer.created',$offer,[],$offer->fresh()->toArray());
        return redirect()->route('admin.offers.edit',$offer)->with('success','Angebot wurde erstellt.');
    }

    public function edit(Offer $offer)
    {
        $offer->load('options','fields');
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

        $fieldRows=$offer->fields->map(function($field){
            $row=$field->toArray();
            $row['options_text']=is_array($field->options)?implode("\n",$field->options):'';
            return $row;
        })->values()->all();

        return view('admin.offers.form',compact('offer','categories','optionRows','fieldRows'));
    }

    public function update(Request $request, Offer $offer, AuditService $audit, ImageSanitizer $images)
    {
        $before=$offer->toArray();
        $offer->update($this->validated($request));
        $this->handleImage($request,$offer,$images);
        $this->syncOptions($request,$offer);
        $this->syncFields($request,$offer);
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
            'capacity'=>['nullable','integer','min:1','max:100000'],
            'available_from'=>['nullable','date'],
            'available_until'=>['nullable','date','after:available_from'],
            'rules_text'=>['nullable','string'],
            'image'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:12288'],
        ]);

        unset($data['rules_text'],$data['image']);
        $data['requires_precheck']=$request->boolean('requires_precheck');
        $data['active']=$request->boolean('active');
        $data['rules']=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$request->input('rules_text','')))));

        return $data;
    }

    private function handleImage(Request $request, Offer $offer, ImageSanitizer $images): void
    {
        if(!$request->hasFile('image')) return;

        $stored=$images->store($request->file('image'),'public','offers',1800,1200);
        $old=$offer->image_path;
        $offer->update(['image_path'=>$stored['path']]);

        if($old && $old!==$stored['path']) Storage::disk('public')->delete($old);
    }

    private function syncFields(Request $request, Offer $offer): void
    {
        $request->validate([
            'fields'=>['nullable','array','max:30'],
            'fields.*.id'=>['nullable','integer'],
            'fields.*.label'=>['nullable','string','max:160'],
            'fields.*.type'=>['nullable','in:text,textarea,number,select,radio,checkbox'],
            'fields.*.help_text'=>['nullable','string','max:500'],
            'fields.*.options_text'=>['nullable','string','max:3000'],
        ]);

        $rows=$request->input('fields',[]);
        $keep=[];

        foreach($rows as $i=>$row){
            $label=trim($row['label']??'');
            if($label==='') continue;

            $existing=!empty($row['id'])?$offer->fields()->find($row['id']):null;
            $key=$existing?->key ?: Str::slug($label,'_');
            if($key==='') $key='feld_'.($i+1);

            if(!$existing){
                $base=$key; $suffix=2;
                while($offer->fields()->where('key',$key)->exists()) $key=$base.'_'.$suffix++;
            }

            $options=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$row['options_text']??''))));

            $field=$offer->fields()->updateOrCreate(
                ['id'=>$existing?->id],
                [
                    'label'=>$label,
                    'key'=>$key,
                    'type'=>$row['type']??'text',
                    'help_text'=>$row['help_text']??null,
                    'options'=>$options ?: null,
                    'required'=>filter_var($row['required']??false,FILTER_VALIDATE_BOOLEAN),
                    'sort_order'=>$i,
                    'active'=>true,
                ]
            );
            $keep[]=$field->id;
        }

        $offer->fields()->whereNotIn('id',$keep?:[0])->update(['active'=>false]);
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
