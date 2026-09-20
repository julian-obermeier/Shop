<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class V1OrderWorkflowService
{
    public function __construct(private OfferPricingService $pricing) {}

    public function accept(User $user, Offer $offer, array $optionIds = [], array $fieldValues = [], bool $rightsAccepted = false): Order
    {
        return DB::transaction(function () use ($user, $offer, $optionIds, $fieldValues, $rightsAccepted) {
            $offer = Offer::with(['category','options','fields'])->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            abort_unless($offer->active && in_array($offer->lifecycle_status ?? 'active', ['active', null], true), 404);
            abort_unless($user->role === 'provider', 403);
            abort_unless($user->hasVerifiedEmail(), 422, 'Bitte bestätige zuerst deine E-Mail-Adresse.');

            $categoryId = (int)$offer->category_id;
            abort_if(
                Order::where('user_id',$user->id)
                    ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started','archived'])
                    ->where(function($q) use($categoryId){
                        $q->whereHas('offer',fn($offerQ)=>$offerQ->where('category_id',$categoryId))
                            ->orWhereJsonContains('offer_snapshot->category_id',$categoryId);
                    })
                    ->lockForUpdate()
                    ->exists(),
                422,
                'Du hast in dieser Kategorie bereits einen offenen oder aktiven Auftrag.'
            );

            $requiredIds=$offer->options->where('active',true)->where('required',true)->pluck('id')->all();
            $optionIds=array_values(array_unique(array_map('intval',array_merge($optionIds,$requiredIds))));
            $options=$offer->options->where('active',true)->whereIn('id',$optionIds);
            abort_if(count($optionIds)!==$options->count(),422,'Ungültige Zusatzoption.');

            $calc=$this->pricing->calculate($offer,$options);
            $normalizedFields=$this->normalizeFieldValues($offer->fields->where('active',true),$fieldValues);
            $isDigital=(bool)$offer->category?->isDigital();

            if($isDigital){
                abort_unless($rightsAccepted,422,'Bitte bestätige die Rechtevereinbarung für diesen digitalen Auftrag.');
            }

            $proofRequirements=$this->normalizedProofRequirements($offer);
            $snapshot=[
                'offer_id'=>$offer->id,
                'offer_version'=>(int)($offer->version_no ?? 1),
                'title'=>$offer->title,
                'category_id'=>$categoryId,
                'category'=>$offer->category?->name,
                'category_kind'=>$offer->category?->kind ?? 'physical',
                'description'=>$offer->description,
                'base_compensation'=>(float)$offer->base_compensation,
                'duration_days'=>$calc['duration_days'],
                'proofs_per_day'=>$calc['proofs_per_day'],
                'shipping_deadline_hours'=>(int)($offer->shipping_deadline_hours ?: 24),
                'rules'=>$offer->rules,
                'proof_requirements'=>$proofRequirements,
                'category_config'=>$offer->category?->config,
                'fulfillment_type'=>$offer->fulfillment_type ?? ($isDigital?'digital':'days'),
                'is_combination'=>(bool)($offer->is_combination ?? false),
            ];

            $order=Order::create([
                'order_number'=>$this->nextOrderNumber(),
                'user_id'=>$user->id,
                'offer_id'=>$offer->id,
                'status'=>$isDigital?'active':'precheck',
                'phase'=>$isDigital?'execution':'preparation',
                'compensation_total'=>$calc['total'],
                'offer_snapshot'=>$snapshot,
                'current_requirements'=>$snapshot,
                'accepted_at'=>now(),
            ]);

            foreach($options as $option){
                $order->options()->create([
                    'offer_option_id'=>$option->id,
                    'name'=>$option->name,
                    'price_delta'=>$option->price_delta,
                    'snapshot'=>$option->toArray(),
                ]);
            }

            foreach($normalizedFields as $entry){
                $order->fieldValues()->create($entry);
            }

            DB::table('order_components')->insert([
                'order_id'=>$order->id,
                'category_id'=>$categoryId,
                'type'=>$isDigital?'digital':'physical',
                'title'=>$offer->title,
                'base_amount'=>$offer->base_compensation,
                'options_amount'=>round($calc['total']-(float)$offer->base_compensation,2),
                'bonus_amount'=>0,
                'adjustment_amount'=>0,
                'status'=>$isDigital?'active':'preparation',
                'config'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            DB::table('order_runs')->insert([
                'order_id'=>$order->id,
                'run_number'=>1,
                'status'=>$isDigital?'active':'preparation',
                'started_at'=>$isDigital?now():null,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            if($isDigital){
                $componentId=DB::table('order_components')->where('order_id',$order->id)->value('id');
                DB::table('digital_components')->insert([
                    'order_id'=>$order->id,
                    'order_component_id'=>$componentId,
                    'title'=>$offer->title,
                    'requirements'=>json_encode([
                        'formats'=>data_get($offer->rules,'digital_formats',['text','audio','video']),
                        'technical'=>data_get($offer->rules,'digital_technical',[]),
                        'content'=>data_get($offer->rules,'digital_requirements',[]),
                    ],JSON_UNESCAPED_UNICODE),
                    'base_amount'=>$calc['total'],
                    'status'=>'draft',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                $rightsText='v1-digital-rights-draft-1';
                DB::table('rights_acceptances')->insert([
                    'order_id'=>$order->id,
                    'user_id'=>$user->id,
                    'version'=>'v1-digital-rights-draft-1',
                    'terms_hash'=>hash('sha256',$rightsText),
                    'accepted_at'=>now(),
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }

            $wallet=$user->walletAccount()->firstOrCreate([]);
            $wallet->entries()->create([
                'order_id'=>$order->id,
                'bucket'=>'pending',
                'entry_type'=>'order_reserved',
                'amount'=>$calc['total'],
                'reference'=>$order->order_number,
                'description'=>'Auftragswert vorgemerkt',
                'metadata'=>['offer_id'=>$offer->id,'offer_version'=>(int)($offer->version_no ?? 1)],
            ]);

            $conversation=$user->conversations()->firstOrCreate(
                ['order_id'=>$order->id],
                ['subject'=>'Auftrag #'.$order->order_number,'status'=>'open','last_message_at'=>now()]
            );

            $order->statusHistory()->create([
                'changed_by'=>$user->id,
                'to_status'=>$order->status,
                'reason'=>$isDigital
                    ? 'Digitaler Auftrag verbindlich angenommen'
                    : 'Auftrag verbindlich angenommen; Vorabkontrolle erforderlich',
            ]);

            return $order->fresh();
        });
    }

    public function activateAfterPrecheck(Order $order, User $admin): Order
    {
        return DB::transaction(function () use ($order,$admin) {
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($order->status,['precheck','precheck_resubmit'],true),422,'Der Auftrag befindet sich nicht in der Vorabkontrolle.');

            $now=CarbonImmutable::now('Europe/Berlin');
            $today=$now->startOfDay();
            $duration=max(1,(int)data_get($order->offer_snapshot,'duration_days',1));
            $windows=$this->proofWindows($order);

            $futureWindows=collect($windows)->filter(function(array $window) use($now,$today){
                $start=CarbonImmutable::parse($today->format('Y-m-d').' '.($window['start']??'00:00'),'Europe/Berlin');
                return $start->greaterThan($now);
            })->values()->all();

            $series=(int)($order->series_number ?: 1);
            $order->days()->where('series_number',$series)->delete();

            if(count($futureWindows)>0){
                $firstDay=$today;
                $this->createDay($order,$series,1,$firstDay,$futureWindows,'regular');

                for($i=2;$i<=$duration;$i++){
                    $this->createDay($order,$series,$i,$firstDay->addDays($i-1),$windows,'regular');
                }
            } else {
                $order->days()->create([
                    'series_number'=>$series,
                    'day_number'=>0,
                    'date'=>$today->toDateString(),
                    'required_proofs'=>0,
                    'plan'=>[],
                    'source_type'=>'start',
                    'status'=>'start_day',
                    'counts_toward_series'=>false,
                ]);
                $firstDay=$today->addDay();
                for($i=1;$i<=$duration;$i++){
                    $this->createDay($order,$series,$i,$firstDay->addDays($i-1),$windows,'regular');
                }
            }

            $end=$firstDay->addDays($duration-1);
            $from=$order->status;
            $order->update([
                'status'=>'active',
                'phase'=>'execution',
                'activation_date'=>$today->toDateString(),
                'start_date'=>$firstDay->toDateString(),
                'end_date'=>$end->toDateString(),
                'confirmed_start_date'=>null,
                'proposed_start_date'=>null,
            ]);

            DB::table('order_runs')
                ->where('order_id',$order->id)
                ->where('run_number',$series)
                ->update(['status'=>'active','started_at'=>now(),'updated_at'=>now()]);

            DB::table('order_components')->where('order_id',$order->id)->update(['status'=>'active','updated_at'=>now()]);

            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>$from,
                'to_status'=>'active',
                'reason'=>'Vorabkontrolle vollständig freigegeben; Auftrag unmittelbar gestartet',
            ]);

            return $order->fresh('days');
        });
    }

    public function createViolation(Order $order, string $type, string $reason, ?int $dayId=null, array $metadata=[]): int
    {
        return (int)DB::table('violations')->insertGetId([
            'order_id'=>$order->id,
            'order_day_id'=>$dayId,
            'type'=>$type,
            'status'=>'open',
            'reason'=>$reason,
            'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE),
            'detected_at'=>now(),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    public function decideViolation(Order $order, int $violationId, User $admin, bool $confirm): void
    {
        DB::transaction(function() use($order,$violationId,$admin,$confirm){
            $violation=DB::table('violations')->where('id',$violationId)->where('order_id',$order->id)->lockForUpdate()->first();
            abort_unless($violation,404);
            abort_unless(in_array($violation->status,['open','reviewed'],true),422,'Dieser Verstoß wurde bereits abschließend entschieden.');

            DB::table('violations')->where('id',$violationId)->update([
                'status'=>$confirm?'confirmed':'discarded',
                'reviewed_at'=>now(),
                'reviewed_by'=>$admin->id,
                'updated_at'=>now(),
            ]);

            if($confirm){
                $metadata=json_decode($violation->metadata ?: '{}',true) ?: [];
                $damageCaseId=(int)($metadata['damage_case_id']??0);

                if($damageCaseId>0){
                    $already=DB::table('extension_days')
                        ->where('order_id',$order->id)
                        ->where('source_type','damage')
                        ->where('source_id',$damageCaseId)
                        ->exists();

                    if(!$already){
                        $this->appendExtensionDay($order,'damage',$damageCaseId,false,0,$violation->reason,$violationId);
                    }
                } else {
                    $this->appendExtensionDay($order,'violation',$violationId,false,0,$violation->reason,$violationId);
                }
            }
        });
    }

    public function addManualExtraDay(Order $order, User $admin, bool $paid, float $amount=0, ?string $reason=null): void
    {
        DB::transaction(function() use($order,$admin,$paid,$amount,$reason){
            $amount=$paid?round(max(0,$amount),2):0;
            $extensionId=$this->appendExtensionDay($order,'manual',null,$paid,$amount,$reason);

            DB::table('manual_extra_days')->insert([
                'order_id'=>$order->id,
                'extension_day_id'=>$extensionId,
                'created_by'=>$admin->id,
                'paid'=>$paid,
                'amount'=>$amount,
                'reason'=>$reason,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            if($paid && $amount>0){
                $order->increment('compensation_total',$amount);
                $wallet=$order->user->walletAccount()->firstOrCreate([]);
                $wallet->entries()->create([
                    'order_id'=>$order->id,
                    'bucket'=>'pending',
                    'entry_type'=>'manual_extra_day_reserved',
                    'amount'=>$amount,
                    'reference'=>$order->order_number,
                    'description'=>'Bezahlter manueller Zusatztag vorgemerkt',
                ]);
            }

            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>$order->status,
                'to_status'=>$order->status,
                'reason'=>'Manueller Zusatztag hinzugefügt'.($paid?' (bezahlt: '.number_format($amount,2,'.','').' EUR)':' (unbezahlt)').($reason?' – '.$reason:''),
            ]);
        });
    }

    public function reportDamage(Order $order, User $user, string $reason, array $evidence=[]): int
    {
        abort_unless($order->user_id===$user->id,403);
        abort_unless($order->status==='active',422,'Eine Beschädigung kann nur während eines laufenden Auftrags gemeldet werden.');

        return (int)DB::table('damage_cases')->insertGetId([
            'order_id'=>$order->id,
            'order_run_id'=>DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$order->series_number)->value('id'),
            'status'=>'reported',
            'reason'=>$reason,
            'initial_evidence'=>json_encode($evidence,JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    public function decideDamage(Order $order, int $damageCaseId, User $admin, bool $accepted, ?string $note=null): void
    {
        DB::transaction(function() use($order,$damageCaseId,$admin,$accepted,$note){
            $case=DB::table('damage_cases')->where('id',$damageCaseId)->where('order_id',$order->id)->lockForUpdate()->first();
            abort_unless($case,404);
            abort_unless(in_array($case->status,['reported','evidence_requested','review'],true),422,'Dieser Beschädigungsvorgang wurde bereits entschieden.');

            DB::table('damage_cases')->where('id',$damageCaseId)->update([
                'status'=>$accepted?'accepted':'rejected',
                'decision_note'=>$note,
                'decided_by'=>$admin->id,
                'decided_at'=>now(),
                'updated_at'=>now(),
            ]);

            if(!$accepted){
                $order->statusHistory()->create([
                    'changed_by'=>$admin->id,
                    'from_status'=>$order->status,
                    'to_status'=>$order->status,
                    'reason'=>'Beschädigung nicht anerkannt; Auftrag mit demselben Artikel fortzusetzen'.($note?' – '.$note:''),
                ]);
                return;
            }

            $currentRun=(int)$order->series_number;
            DB::table('order_runs')->where('order_id',$order->id)->where('run_number',$currentRun)->update([
                'status'=>'restarted',
                'ended_at'=>now(),
                'restart_reason'=>$case->reason,
                'updated_at'=>now(),
            ]);

            $newRun=$currentRun+1;
            DB::table('order_runs')->insert([
                'order_id'=>$order->id,
                'run_number'=>$newRun,
                'status'=>'preparation',
                'restart_reason'=>$case->reason,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            $order->update([
                'status'=>'precheck',
                'phase'=>'preparation',
                'series_number'=>$newRun,
                'series_interruptions'=>0,
                'activation_date'=>null,
                'start_date'=>null,
                'end_date'=>null,
            ]);

            $order->statusHistory()->create([
                'changed_by'=>$admin->id,
                'from_status'=>'active',
                'to_status'=>'precheck',
                'reason'=>'Beschädigung anerkannt; vollständiger Neustart mit neuem Artikel und neuer Vorabkontrolle',
            ]);
        });
    }

    private function appendExtensionDay(Order $order, string $sourceType, ?int $sourceId, bool $paid, float $amount, ?string $reason, ?int $violationId=null): int
    {
        $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $lastDate=$order->days()->max('date');
        $date=$lastDate
            ? CarbonImmutable::parse($lastDate,'Europe/Berlin')->addDay()
            : CarbonImmutable::parse($order->end_date ?: now('Europe/Berlin')->toDateString(),'Europe/Berlin')->addDay();

        $sequence=(int)DB::table('extension_days')->where('order_id',$order->id)->max('sequence_no')+1;
        $dayNumber=(int)$order->days()->where('series_number',$order->series_number)->max('day_number')+1;
        $windows=$this->proofWindows($order);

        $day=$order->days()->create([
            'series_number'=>$order->series_number,
            'day_number'=>$dayNumber,
            'date'=>$date->toDateString(),
            'required_proofs'=>$this->proofCount($windows),
            'plan'=>$windows,
            'source_type'=>$sourceType,
            'source_id'=>$sourceId,
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);

        $extensionId=(int)DB::table('extension_days')->insertGetId([
            'order_id'=>$order->id,
            'violation_id'=>$violationId,
            'source_type'=>$sourceType,
            'source_id'=>$sourceId,
            'sequence_no'=>$sequence,
            'date'=>$date->toDateString(),
            'paid'=>$paid,
            'amount'=>$amount,
            'reason'=>$reason,
            'status'=>'planned',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        $order->update(['end_date'=>$date->toDateString()]);
        return $extensionId;
    }

    private function createDay(Order $order, int $series, int $dayNumber, CarbonImmutable $date, array $windows, string $source): OrderDay
    {
        return $order->days()->create([
            'series_number'=>$series,
            'day_number'=>$dayNumber,
            'date'=>$date->toDateString(),
            'required_proofs'=>$this->proofCount($windows),
            'plan'=>$windows,
            'source_type'=>$source,
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);
    }

    private function normalizedProofRequirements(Offer $offer): array
    {
        $requirements=$offer->proof_requirements;
        if(is_array($requirements) && count($requirements)>0){
            return array_values($requirements);
        }

        $count=max(0,(int)$offer->proofs_per_day);
        if($count===0) return [];

        return [
            ['key'=>'morning','label'=>'Morgen','start'=>'06:00','end'=>'10:00','required_images'=>$count],
            ['key'=>'midday','label'=>'Mittag','start'=>'12:00','end'=>'16:00','required_images'=>$count],
            ['key'=>'evening','label'=>'Abend','start'=>'18:00','end'=>'23:59','required_images'=>$count],
        ];
    }

    private function proofWindows(Order $order): array
    {
        $requirements=data_get($order->current_requirements ?: $order->offer_snapshot,'proof_requirements',[]);
        return is_array($requirements)?array_values($requirements):[];
    }

    private function proofCount(array $windows): int
    {
        return collect($windows)->sum(fn($window)=>(int)($window['required_images']??0));
    }

    private function nextOrderNumber(): string
    {
        $year=now('Europe/Berlin')->format('Y');
        $numbers=Order::where('order_number','like',$year.'%')->lockForUpdate()->pluck('order_number');
        $max=0;

        foreach($numbers as $number){
            if(preg_match('/^'.preg_quote($year,'/').'([0-9]{4})$/',(string)$number,$matches)){
                $max=max($max,(int)$matches[1]);
            }
        }

        $next=$max+1;
        abort_if($next>9999,422,'Der jährliche Auftragsnummernkreis ist ausgeschöpft.');

        return $year.str_pad((string)$next,4,'0',STR_PAD_LEFT);
    }

    private function normalizeFieldValues($fields, array $values): array
    {
        $normalized=[];
        foreach($fields as $field){
            $value=$values[$field->key]??null;
            if($field->type==='checkbox'){
                $value=in_array($value,[1,'1',true,'on'],true)?'1':'0';
            } elseif(is_string($value)){
                $value=trim($value);
            }

            if($field->required){
                abort_if($value===null || $value==='' || ($field->type==='checkbox' && $value!=='1'),422,'Das Feld „'.$field->label.'“ ist erforderlich.');
            }

            if(in_array($field->type,['select','radio'],true) && $value!==null && $value!==''){
                abort_unless(in_array((string)$value,array_map('strval',$field->options??[]),true),422,'Ungültiger Wert für „'.$field->label.'“.');
            }

            if($field->type==='number' && $value!==null && $value!==''){
                abort_unless(is_numeric($value),422,'Das Feld „'.$field->label.'“ muss eine Zahl enthalten.');
            }

            $normalized[]=[
                'offer_field_id'=>$field->id,
                'label'=>$field->label,
                'key'=>$field->key,
                'value'=>is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):$value,
                'field_snapshot'=>$field->toArray(),
            ];
        }

        return $normalized;
    }
}
