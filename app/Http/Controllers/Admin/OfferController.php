<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function index()
    {
        $offers=Offer::with('category')->withCount([
            'orders as active_orders_count'=>fn($q)=>$q->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started','archived'])
        ])->latest()->paginate(30);
        return view('admin.offers.index',compact('offers'));
    }

    public function create()
    {
        $categories=Category::where('active',true)->whereNotNull('parent_id')->orderBy('sort_order')->orderBy('name')->get();
        return view('admin.offers.form',[
            'offer'=>new Offer(['lifecycle_status'=>'draft','fulfillment_type'=>'days','tracking_mode'=>'optional']),
            'categories'=>$categories,
            'optionRows'=>[],
            'fieldRows'=>[],
            'proofRows'=>$this->standardProofWindows(),
        ]);
    }

    public function store(Request $request)
    {
        $validated=$this->validated($request);
        $offer=DB::transaction(function() use($request,$validated){
            $offer=Offer::create($validated+[
                'slug'=>Str::slug($request->title).'-'.Str::lower(Str::random(5)),
                'version_no'=>1,
            ]);
            $this->syncOptions($request,$offer);
            $this->syncFields($request,$offer);
            $this->snapshotVersion($offer,1);
            return $offer;
        });

        return redirect()->route('admin.offers.edit',$offer)->with('success','Angebot wurde als Version 1 gespeichert.');
    }

    public function edit(Offer $offer)
    {
        $offer->load('options','fields');
        $categories=Category::where('active',true)->whereNotNull('parent_id')->orderBy('sort_order')->orderBy('name')->get();

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

        $proofRows=collect($offer->proof_requirements ?: $this->standardProofWindows())->map(function($row){
            $row['required_fields_text']=collect($row['required_fields']??[])->pluck('label')->filter()->implode("\n");
            return $row;
        })->values()->all();

        return view('admin.offers.form',compact('offer','categories','optionRows','fieldRows','proofRows'));
    }

    public function update(Request $request, Offer $offer)
    {
        DB::transaction(function() use($request,$offer){
            $offer->update($this->validated($request));
            $this->syncOptions($request,$offer);
            $this->syncFields($request,$offer);
            $next=(int)$offer->version_no+1;
            $offer->update(['version_no'=>$next]);
            $this->snapshotVersion($offer,$next);
        });

        return back()->with('success','Angebot wurde gespeichert. Neue Angebotsversion: '.$offer->fresh()->version_no.'.');
    }

    public function duplicate(Offer $offer)
    {
        $offer->load('options','fields');
        $copy=DB::transaction(function() use($offer){
            $copy=$offer->replicate();
            $copy->title=$offer->title.' – Kopie';
            $copy->slug=Str::slug($copy->title).'-'.Str::lower(Str::random(5));
            $copy->active=false;
            $copy->lifecycle_status='draft';
            $copy->version_no=1;
            $copy->save();

            $optionIdMap=[];
            foreach($offer->options as $option){
                $new=$option->replicate();
                $new->offer_id=$copy->id;
                $new->save();
                $optionIdMap[(int)$option->id]=(int)$new->id;
            }
            foreach($offer->options as $option){
                $newId=$optionIdMap[(int)$option->id]??null;
                if(!$newId) continue;
                $newOption=$copy->options()->findOrFail($newId);
                $rules=$newOption->rules ?: [];
                foreach(['requires_ids','excludes_ids'] as $key){
                    $rules[$key]=collect($rules[$key]??[])->map(fn($oldId)=>$optionIdMap[(int)$oldId]??null)->filter()->values()->all();
                }
                $newOption->update(['rules'=>$rules]);
            }
            foreach($offer->fields as $field){
                $new=$field->replicate();
                $new->offer_id=$copy->id;
                $new->save();
            }
            $this->snapshotVersion($copy,1);
            return $copy;
        });

        return redirect()->route('admin.offers.edit',$copy)->with('success','Angebot wurde als neuer Entwurf dupliziert.');
    }

    public function destroy(Request $request, Offer $offer)
    {
        $request->validate(['confirm_delete'=>['accepted']]);
        abort_if($offer->orders()->exists(),422,'Dieses Angebot ist bereits mit Aufträgen verknüpft und kann nur deaktiviert, nicht gelöscht werden.');
        $offer->delete();
        return redirect()->route('admin.offers.index')->with('success','Unbenutztes Angebot wurde gelöscht.');
    }

    private function validated(Request $request): array
    {
        $data=$request->validate([
            'category_id'=>['required','exists:categories,id'],
            'title'=>['required','string','max:180'],
            'short_description'=>['nullable','string','max:500'],
            'description'=>['nullable','string'],
            'base_compensation'=>['required','numeric','min:0'],
            'duration_days'=>['required','integer','min:1'],
            'fulfillment_type'=>['required','in:days,units,one_time,digital,combination'],
            'lifecycle_status'=>['required','in:draft,active,deactivated'],
            'tracking_mode'=>['required','in:required,optional,none'],
            'rules_text'=>['nullable','string'],
            'proof_windows'=>['nullable','array','max:20'],
            'proof_windows.*.key'=>['nullable','string','max:80'],
            'proof_windows.*.label'=>['required_with:proof_windows','string','max:160'],
            'proof_windows.*.start'=>['required_with:proof_windows','date_format:H:i'],
            'proof_windows.*.end'=>['required_with:proof_windows','date_format:H:i'],
            'proof_windows.*.required_images'=>['required_with:proof_windows','integer','min:1','max:20'],
            'proof_windows.*.image_requirements'=>['nullable','string','max:2000'],
            'proof_windows.*.required_fields_text'=>['nullable','string','max:4000'],
        ]);

        $category=Category::findOrFail($data['category_id']);
        $proofWindows=$this->normalizeProofWindows($request,$data['proof_windows']??[]);
        if($category->kind!=='digital' && count($proofWindows)===0) $proofWindows=$this->standardProofWindows();

        unset($data['rules_text'],$data['proof_windows']);
        $data['active']=$data['lifecycle_status']==='active';
        $data['requires_precheck']=$category->kind!=='digital';
        $data['is_sock_wearing']=false;
        $data['is_combination']=$data['fulfillment_type']==='combination';
        $data['capacity']=null;
        $data['minimum_minutes_per_day']=0;
        $data['shipping_deadline_hours']=24;
        $data['proof_requirements']=$proofWindows;
        $data['proofs_per_day']=collect($proofWindows)->sum('required_images');
        $data['rules']=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$request->input('rules_text','')))));
        $data['inspection_config']=null;

        return $data;
    }

    private function normalizeProofWindows(Request $request, array $rows): array
    {
        $proofWindows=[];
        foreach($rows as $i=>$row){
            $key=Str::slug((string)($row['key']?:$row['label']),'_');
            if($key==='') $key='window_'.($i+1);
            $base=$key; $suffix=2;
            while(collect($proofWindows)->contains(fn($x)=>$x['key']===$key)) $key=$base.'_'.$suffix++;

            $requiredFields=[];
            foreach(preg_split('/\r\n|\r|\n/',(string)($row['required_fields_text']??'')) as $j=>$label){
                $label=trim($label); if($label==='') continue;
                $fieldKey=Str::slug($label,'_') ?: 'field_'.($j+1);
                $requiredFields[]=['key'=>$fieldKey,'label'=>$label];
            }

            $proofWindows[]=[
                'key'=>$key,'label'=>trim($row['label']),
                'start'=>$row['start'],'end'=>$row['end'],
                'required_images'=>(int)$row['required_images'],
                'text_required'=>$request->boolean("proof_windows.$i.text_required"),
                'face_required'=>$request->boolean("proof_windows.$i.face_required"),
                'image_requirements'=>trim((string)($row['image_requirements']??'')) ?: null,
                'required_fields'=>$requiredFields,
            ];
        }
        return $proofWindows;
    }

    private function syncFields(Request $request, Offer $offer): void
    {
        $request->validate([
            'fields'=>['nullable','array','max:50'],
            'fields.*.id'=>['nullable','integer'],
            'fields.*.label'=>['nullable','string','max:160'],
            'fields.*.type'=>['nullable','in:text,textarea,number,select,radio,checkbox,date,multiselect'],
            'fields.*.help_text'=>['nullable','string','max:500'],
            'fields.*.options_text'=>['nullable','string','max:3000'],
        ]);
        $keep=[];
        foreach($request->input('fields',[]) as $i=>$row){
            $label=trim($row['label']??''); if($label==='') continue;
            $existing=!empty($row['id'])?$offer->fields()->find($row['id']):null;
            $key=$existing?->key ?: (Str::slug($label,'_') ?: 'feld_'.($i+1));
            if(!$existing){$base=$key;$n=2;while($offer->fields()->where('key',$key)->exists())$key=$base.'_'.$n++;}
            $options=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$row['options_text']??''))));
            $field=$offer->fields()->updateOrCreate(['id'=>$existing?->id],[
                'label'=>$label,'key'=>$key,'type'=>$row['type']??'text','help_text'=>$row['help_text']??null,
                'options'=>$options?:null,'required'=>filter_var($row['required']??false,FILTER_VALIDATE_BOOLEAN),
                'sort_order'=>$i,'active'=>true,
            ]);
            $keep[]=$field->id;
        }
        $offer->fields()->whereNotIn('id',$keep?:[0])->update(['active'=>false]);
    }

    private function syncOptions(Request $request, Offer $offer): void
    {
        $request->validate([
            'options'=>['nullable','array','max:50'],
            'options.*.name'=>['nullable','string','max:120'],
            'options.*.description'=>['nullable','string','max:500'],
            'options.*.price_delta'=>['nullable','numeric','min:0'],
            'options.*.extra_proofs_per_day'=>['nullable','integer','min:0'],
            'options.*.extra_duration_days'=>['nullable','integer','min:0'],
            'options.*.min_duration_days'=>['nullable','integer','min:0'],
            'options.*.requires_names'=>['nullable','string','max:500'],
            'options.*.excludes_names'=>['nullable','string','max:500'],
        ]);

        $rows=$request->input('options',[]); $keep=[]; $saved=[];
        foreach($rows as $i=>$row){
            $name=trim($row['name']??''); if($name==='') continue;
            $opt=$offer->options()->updateOrCreate(['id'=>$row['id']??null],[
                'name'=>$name,'description'=>$row['description']??null,'price_delta'=>(float)($row['price_delta']??0),
                'extra_proofs_per_day'=>(int)($row['extra_proofs_per_day']??0),
                'extra_duration_days'=>(int)($row['extra_duration_days']??0),
                'required'=>filter_var($row['required']??false,FILTER_VALIDATE_BOOLEAN),'active'=>true,'sort_order'=>$i,
            ]);
            $keep[]=$opt->id; $saved[$i]=$opt;
        }
        $offer->options()->whereNotIn('id',$keep?:[0])->update(['active'=>false]);
        $nameMap=$offer->options()->where('active',true)->get()->mapWithKeys(fn($o)=>[mb_strtolower(trim($o->name))=>$o->id]);
        foreach($saved as $i=>$opt){
            $row=$rows[$i];
            $parse=fn($v)=>collect(explode(',',$v??''))->map(fn($n)=>mb_strtolower(trim($n)))->filter()->map(fn($n)=>$nameMap->get($n))->filter(fn($id)=>$id && (int)$id!==$opt->id)->map(fn($id)=>(int)$id)->unique()->values()->all();
            $opt->update(['rules'=>[
                'requires_ids'=>$parse($row['requires_names']??''),
                'excludes_ids'=>$parse($row['excludes_names']??''),
                'min_duration_days'=>(int)($row['min_duration_days']??0),
            ]]);
        }
    }

    private function snapshotVersion(Offer $offer, int $version): void
    {
        $offer->load('category','options','fields');
        DB::table('offer_versions')->updateOrInsert(
            ['offer_id'=>$offer->id,'version'=>$version],
            [
                'snapshot'=>json_encode($offer->toArray(),JSON_UNESCAPED_UNICODE),
                'created_by'=>auth()->id(),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]
        );
    }

    private function standardProofWindows(): array
    {
        return [
            ['key'=>'morning','label'=>'Morgen','start'=>'06:00','end'=>'10:00','required_images'=>1,'text_required'=>false,'face_required'=>false],
            ['key'=>'midday','label'=>'Mittag','start'=>'12:00','end'=>'16:00','required_images'=>1,'text_required'=>false,'face_required'=>false],
            ['key'=>'evening','label'=>'Abend','start'=>'18:00','end'=>'23:59','required_images'=>1,'text_required'=>false,'face_required'=>false],
        ];
    }
}
