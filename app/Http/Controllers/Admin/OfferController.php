<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Offer;
use App\Services\AuditService;
use App\Services\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class OfferController extends Controller
{
    public function index()
    {
        $offers=Offer::with('category')->withCount([
            'orders as active_orders_count'=>fn($q)=>$q->whereNotIn('status',['completed','cancelled','rejected','not_started'])
        ])->latest()->paginate(30);

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
            'proofRows'=>[
                ['key'=>'daily','label'=>'Tagesnachweis','start'=>'00:00','end'=>'23:59','required_images'=>1,'text_required'=>false,'face_required'=>false],
            ],
            'inspectionConfig'=>$this->defaultInspectionConfig(),
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

        $proofRows=collect($offer->proof_requirements ?: [
            ['key'=>'daily','label'=>'Tagesnachweis','start'=>'00:00','end'=>'23:59','required_images'=>max(1,(int)$offer->proofs_per_day),'text_required'=>false,'face_required'=>false],
        ])->map(function($row){
            $row['required_fields_text']=collect($row['required_fields']??[])
                ->pluck('label')
                ->filter()
                ->implode("\n");
            return $row;
        })->values()->all();
        $inspectionConfig=$offer->inspection_config ?: $this->defaultInspectionConfig();

        return view('admin.offers.form',compact('offer','categories','optionRows','fieldRows','proofRows','inspectionConfig'));
    }

    public function update(Request $request, Offer $offer, AuditService $audit, ImageSanitizer $images)
    {
        $before=$offer->toArray();
        $wasActive=(bool)$offer->active;
        $validated=$this->validated($request);

        \Illuminate\Support\Facades\DB::transaction(function() use($offer,$validated,$wasActive){
            $offer->update($validated);

            if($wasActive && !$offer->active){
                $offer->waitlistEntries()
                    ->where('status','reserved')
                    ->whereNotNull('reservation_expires_at')
                    ->get()
                    ->each(function($entry){
                        $remaining=max(1,now()->diffInSeconds($entry->reservation_expires_at,false));
                        $entry->update([
                            'reservation_remaining_seconds'=>$remaining,
                            'reservation_expires_at'=>null,
                        ]);
                    });
            }

            if(!$wasActive && $offer->active){
                $offer->waitlistEntries()
                    ->where('status','reserved')
                    ->get()
                    ->each(function($entry){
                        $seconds=max(1,(int)($entry->reservation_remaining_seconds ?: 86400));
                        $entry->update([
                            'reserved_at'=>now(),
                            'reservation_expires_at'=>now()->addSeconds($seconds),
                            'reservation_remaining_seconds'=>null,
                        ]);
                    });
            }
        });

        $this->handleImage($request,$offer,$images);
        $this->syncOptions($request,$offer);
        $this->syncFields($request,$offer);

        $audit->log('offer.updated',$offer,$before,$offer->fresh()->toArray());

        return back()->with('success','Angebot wurde gespeichert.');
    }

    public function duplicate(Offer $offer, AuditService $audit)
    {
        $offer->load('options','fields');

        $copy=$offer->replicate();
        $copy->title=$offer->title.' – Kopie';
        $copy->slug=Str::slug($copy->title).'-'.Str::lower(Str::random(5));
        $copy->active=false;
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

            foreach(['requires_ids','excludes_ids'] as $ruleKey){
                $rules[$ruleKey]=collect($rules[$ruleKey]??[])
                    ->map(fn($oldId)=>$optionIdMap[(int)$oldId]??null)
                    ->filter()
                    ->map(fn($id)=>(int)$id)
                    ->unique()
                    ->values()
                    ->all();
            }

            $newOption->update(['rules'=>$rules]);
        }

        foreach($offer->fields as $field){
            $new=$field->replicate();
            $new->offer_id=$copy->id;
            $new->save();
        }

        $audit->log('offer.duplicated',$copy,[],['source_offer_id'=>$offer->id]+$copy->toArray());

        return redirect()->route('admin.offers.edit',$copy)->with('success','Angebot wurde vollständig dupliziert und als inaktiver Entwurf angelegt.');
    }

    public function destroy(Offer $offer, AuditService $audit)
    {
        $before=$offer->toArray();
        $audit->log('offer.deleted',$offer,$before,[]);
        $offer->delete();

        return redirect()->route('admin.offers.index')->with('success','Angebot wurde gelöscht. Bestehende Aufträge behalten ihren Snapshot.');
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
            'capacity'=>['nullable','integer','min:1','max:100000'],
            'tracking_mode'=>['required','in:required,optional,none'],
            'rules_text'=>['nullable','string'],
            'image'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:12288'],
            'proof_windows'=>['required','array','min:1','max:20'],
            'proof_windows.*.key'=>['nullable','string','max:80'],
            'proof_windows.*.label'=>['required','string','max:160'],
            'proof_windows.*.start'=>['required','date_format:H:i'],
            'proof_windows.*.end'=>['required','date_format:H:i'],
            'proof_windows.*.required_images'=>['required','integer','min:1','max:20'],
            'proof_windows.*.image_requirements'=>['nullable','string','max:2000'],
            'proof_windows.*.required_fields_text'=>['nullable','string','max:4000'],
            'points_affect_compensation'=>['nullable','boolean'],
            'score_bands'=>['nullable','string','max:5000'],
        ]);

        $proofWindows=[];
        foreach($data['proof_windows'] as $i=>$row){
            $key=Str::slug((string)($row['key']?:$row['label']),'_');
            if($key==='') $key='window_'.($i+1);
            $base=$key;
            $suffix=2;
            while(collect($proofWindows)->contains(fn($existing)=>$existing['key']===$key)) $key=$base.'_'.$suffix++;

            $requiredFields=[];
            foreach(preg_split('/\r\n|\r|\n/',(string)($row['required_fields_text']??'')) as $fieldIndex=>$fieldLabel){
                $fieldLabel=trim($fieldLabel);
                if($fieldLabel==='') continue;

                $fieldKey=Str::slug($fieldLabel,'_');
                if($fieldKey==='') $fieldKey='pflichtangabe_'.($fieldIndex+1);
                $baseFieldKey=$fieldKey;
                $fieldSuffix=2;
                while(collect($requiredFields)->contains(fn($existing)=>$existing['key']===$fieldKey)){
                    $fieldKey=$baseFieldKey.'_'.$fieldSuffix++;
                }

                $requiredFields[]=[
                    'key'=>$fieldKey,
                    'label'=>$fieldLabel,
                ];
            }

            $proofWindows[]=[
                'key'=>$key,
                'label'=>trim($row['label']),
                'start'=>$row['start'],
                'end'=>$row['end'],
                'required_images'=>(int)$row['required_images'],
                'text_required'=>filter_var($request->input("proof_windows.$i.text_required",false),FILTER_VALIDATE_BOOLEAN),
                'face_required'=>filter_var($request->input("proof_windows.$i.face_required",false),FILTER_VALIDATE_BOOLEAN),
                'image_requirements'=>trim((string)($row['image_requirements']??'')) ?: null,
                'required_fields'=>$requiredFields,
            ];
        }

        $bands=[];
        foreach(preg_split('/\r\n|\r|\n/',(string)($data['score_bands']??'')) as $line){
            $line=trim($line);
            if($line==='') continue;
            if(!preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s*=\s*(\d{1,3}(?:[.,]\d+)?)$/',$line,$m)){
                abort(422,'Punktebänder müssen im Format 45-50=100 angegeben werden.');
            }
            $min=(int)$m[1]; $max=(int)$m[2]; $percent=(float)str_replace(',','.',$m[3]);
            abort_if($min<0 || $max>50 || $min>$max || $percent<0 || $percent>100,422,'Ungültiges Punkteband: '.$line);
            $bands[]=['min'=>$min,'max'=>$max,'percentage'=>$percent];
        }

        $categories=[];
        foreach(['appearance'=>'Aussehen','smell'=>'Geruch','taste'=>'Geschmack','proofs'=>'Nachweise','extras'=>'Extras'] as $key=>$label){
            $categories[$key]=[
                'label'=>$label,
                'ko'=>$request->boolean('inspection_ko_'.$key),
            ];
        }

        unset($data['rules_text'],$data['image'],$data['proof_windows'],$data['points_affect_compensation'],$data['score_bands']);

        $data['requires_precheck']=$request->boolean('requires_precheck');
        $data['is_sock_wearing']=$request->boolean('is_sock_wearing');
        $data['active']=$request->boolean('active');
        $data['available_from']=null;
        $data['available_until']=null;
        $data['shipping_deadline_hours']=24;
        $data['proof_requirements']=$proofWindows;
        $data['proofs_per_day']=collect($proofWindows)->sum('required_images');
        $data['rules']=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$request->input('rules_text','')))));
        $data['inspection_config']=[
            'categories'=>$categories,
            'points_affect_compensation'=>$request->boolean('points_affect_compensation'),
            'score_bands'=>$bands,
            'start_face_required'=>$request->boolean('start_face_required'),
        ];

        return $data;
    }

    private function handleImage(Request $request, Offer $offer, ImageSanitizer $images): void
    {
        if(!$request->hasFile('image')) return;

        $stored=$images->store($request->file('image'),'public','offers',1800,1200);
        $offer->update(['image_path'=>$stored['path']]);
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

    private function defaultInspectionConfig(): array
    {
        return [
            'categories'=>[
                'appearance'=>['label'=>'Aussehen','ko'=>false],
                'smell'=>['label'=>'Geruch','ko'=>false],
                'taste'=>['label'=>'Geschmack','ko'=>false],
                'proofs'=>['label'=>'Nachweise','ko'=>false],
                'extras'=>['label'=>'Extras','ko'=>false],
            ],
            'points_affect_compensation'=>false,
            'score_bands'=>[],
            'start_face_required'=>false,
        ];
    }
}
