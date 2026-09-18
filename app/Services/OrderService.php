<?php
namespace App\Services;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private OfferPricingService $pricing) {}

    public function create(User $user, Offer $offer, array $optionIds): Order
    {
        return DB::transaction(function () use ($user,$offer,$optionIds) {
            $requiredIds=$offer->options()->where('active',true)->where('required',true)->pluck('id')->all();
            $optionIds=array_values(array_unique(array_map('intval',array_merge($optionIds,$requiredIds))));
            $options=$offer->options()->where('active',true)->whereIn('id',$optionIds)->get();
            abort_if(count($optionIds)!==$options->count(),422,'Ungültige Zusatzoption.');

            $calc=$this->pricing->calculate($offer,$options);
            $number=now()->format('Y').str_pad((string)((Order::max('id')??0)+1),7,'0',STR_PAD_LEFT);
            $snapshot=[
                'offer_id'=>$offer->id,'title'=>$offer->title,'category'=>$offer->category?->name,
                'description'=>$offer->description,'base_compensation'=>(float)$offer->base_compensation,
                'duration_days'=>$calc['duration_days'],'proofs_per_day'=>$calc['proofs_per_day'],
                'minimum_minutes_per_day'=>$offer->minimum_minutes_per_day,'shipping_deadline_hours'=>$offer->shipping_deadline_hours,
                'requires_precheck'=>$offer->requires_precheck,'rules'=>$offer->rules,
            ];

            $order=Order::create([
                'order_number'=>$number,'user_id'=>$user->id,'offer_id'=>$offer->id,
                'status'=>$offer->requires_precheck?'precheck':'approved',
                'compensation_total'=>$calc['total'],'offer_snapshot'=>$snapshot,'accepted_at'=>now(),
            ]);

            foreach($options as $option){
                $order->options()->create([
                    'offer_option_id'=>$option->id,'name'=>$option->name,'price_delta'=>$option->price_delta,
                    'snapshot'=>$option->toArray(),
                ]);
            }

            $order->statusHistory()->create(['changed_by'=>$user->id,'to_status'=>$order->status,'reason'=>'Auftrag aus Angebot erstellt']);
            $wallet=WalletAccount::firstOrCreate(['user_id'=>$user->id]);
            $wallet->entries()->create(['order_id'=>$order->id,'bucket'=>'pending','entry_type'=>'order_reserved','amount'=>$calc['total'],'reference'=>$order->order_number,'description'=>'Vergütung vorgemerkt']);
            return $order;
        });
    }

    public function start(Order $order): void
    {
        DB::transaction(function () use ($order) {
            if(!in_array($order->status,['approved','waiting_start'],true)) abort(422,'Auftrag kann nicht gestartet werden.');
            $duration=(int)data_get($order->offer_snapshot,'duration_days',1);
            $proofs=(int)data_get($order->offer_snapshot,'proofs_per_day',0);
            $start=today(); $end=$start->copy()->addDays($duration-1);
            $order->update(['status'=>'active','start_date'=>$start,'end_date'=>$end]);
            for($i=1;$i<=$duration;$i++) $order->days()->firstOrCreate(['day_number'=>$i],['date'=>$start->copy()->addDays($i-1),'required_proofs'=>$proofs,'status'=>'open']);
            $order->statusHistory()->create(['changed_by'=>auth()->id(),'from_status'=>'approved','to_status'=>'active','reason'=>'Auftrag gestartet']);
        });
    }
}
