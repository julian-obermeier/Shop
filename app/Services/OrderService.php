<?php
namespace App\Services;

use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(private OfferPricingService $pricing) {}

    public function create(User $user, Offer $offer, array $optionIds, array $fieldValues=[]): Order
    {
        return DB::transaction(function () use ($user,$offer,$optionIds,$fieldValues) {
            $lockedOffer=Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            abort_unless($lockedOffer->active,404);
            abort_if($lockedOffer->available_from && $lockedOffer->available_from->isFuture(),422,'Dieses Angebot ist noch nicht verfügbar.');
            abort_if($lockedOffer->available_until && $lockedOffer->available_until->isPast(),422,'Dieses Angebot ist nicht mehr verfügbar.');

            if($lockedOffer->capacity){
                $used=Order::where('offer_id',$lockedOffer->id)
                    ->whereNotIn('status',['cancelled','rejected'])
                    ->count();
                abort_if($used >= $lockedOffer->capacity,422,'Dieses Angebot ist bereits vollständig vergeben.');
            }

            $requiredIds=$lockedOffer->options()->where('active',true)->where('required',true)->pluck('id')->all();
            $optionIds=array_values(array_unique(array_map('intval',array_merge($optionIds,$requiredIds))));
            $options=$lockedOffer->options()->where('active',true)->whereIn('id',$optionIds)->get();
            abort_if(count($optionIds)!==$options->count(),422,'Ungültige Zusatzoption.');

            $calc=$this->pricing->calculate($lockedOffer,$options);
            $fields=$lockedOffer->fields()->where('active',true)->get();
            $normalizedFields=$this->normalizeFieldValues($fields,$fieldValues);

            $snapshot=[
                'offer_id'=>$lockedOffer->id,
                'title'=>$lockedOffer->title,
                'category'=>$lockedOffer->category?->name,
                'description'=>$lockedOffer->description,
                'image_path'=>$lockedOffer->image_path,
                'base_compensation'=>(float)$lockedOffer->base_compensation,
                'duration_days'=>$calc['duration_days'],
                'proofs_per_day'=>$calc['proofs_per_day'],
                'minimum_minutes_per_day'=>$lockedOffer->minimum_minutes_per_day,
                'shipping_deadline_hours'=>$lockedOffer->shipping_deadline_hours,
                'requires_precheck'=>$lockedOffer->requires_precheck,
                'rules'=>$lockedOffer->rules,
                'fields'=>$fields->map(fn($field)=>$field->toArray())->values()->all(),
            ];

            $order=Order::create([
                'order_number'=>'TMP-'.Str::uuid(),
                'user_id'=>$user->id,
                'offer_id'=>$lockedOffer->id,
                'status'=>$lockedOffer->requires_precheck?'precheck':'approved',
                'compensation_total'=>$calc['total'],
                'offer_snapshot'=>$snapshot,
                'accepted_at'=>now(),
            ]);

            $order->update([
                'order_number'=>now()->format('Y').str_pad((string)$order->id,7,'0',STR_PAD_LEFT),
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

            $order->statusHistory()->create([
                'changed_by'=>$user->id,
                'to_status'=>$order->status,
                'reason'=>'Auftrag aus Angebot erstellt',
            ]);

            $wallet=WalletAccount::firstOrCreate(['user_id'=>$user->id]);
            $wallet->entries()->create([
                'order_id'=>$order->id,
                'bucket'=>'pending',
                'entry_type'=>'order_reserved',
                'amount'=>$calc['total'],
                'reference'=>$order->order_number,
                'description'=>'Vergütung vorgemerkt',
            ]);

            return $order;
        });
    }

    public function start(Order $order): void
    {
        DB::transaction(function () use ($order) {
            if(!in_array($order->status,['approved','waiting_start'],true)){
                abort(422,'Auftrag kann nicht gestartet werden.');
            }

            $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
            $proofs=(int)data_get($order->offer_snapshot,'proofs_per_day',0);
            $start=today();
            $end=$start->copy()->addDays($duration-1);

            $order->update([
                'status'=>'active',
                'start_date'=>$start,
                'end_date'=>$end,
            ]);

            for($i=1;$i<=$duration;$i++){
                $order->days()->firstOrCreate(
                    ['day_number'=>$i],
                    [
                        'date'=>$start->copy()->addDays($i-1),
                        'required_proofs'=>$proofs,
                        'status'=>'open',
                    ]
                );
            }

            $order->statusHistory()->create([
                'changed_by'=>auth()->id(),
                'from_status'=>'approved',
                'to_status'=>'active',
                'reason'=>'Auftrag gestartet',
            ]);
        });
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

            if(is_array($value)){
                $value=json_encode($value,JSON_UNESCAPED_UNICODE);
            }

            $normalized[]=[
                'offer_field_id'=>$field->id,
                'label'=>$field->label,
                'key'=>$field->key,
                'value'=>$value,
                'field_snapshot'=>$field->toArray(),
            ];
        }

        return $normalized;
    }
}
