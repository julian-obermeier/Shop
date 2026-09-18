<?php
namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Collection;

class OfferPricingService
{
    public function calculate(Offer $offer, Collection $options): array
    {
        $selectedIds=$options->pluck('id')->map(fn($id)=>(int)$id)->all();
        $total=(float)$offer->base_compensation;
        $duration=(int)$offer->duration_days;
        $proofsPerDay=(int)$offer->proofs_per_day;

        foreach($options as $option){
            $total+=(float)$option->price_delta;
            $duration+=(int)$option->extra_duration_days;
            $proofsPerDay+=(int)$option->extra_proofs_per_day;
        }

        foreach($options as $option){
            $rules=$option->rules ?: [];
            $requires=array_map('intval',$rules['requires_ids']??[]);
            $excludes=array_map('intval',$rules['excludes_ids']??[]);
            $minimumDuration=(int)($rules['min_duration_days']??0);

            foreach($requires as $requiredId){
                abort_unless(in_array($requiredId,$selectedIds,true),422,'Die Option „'.$option->name.'“ benötigt eine weitere Zusatzoption.');
            }
            foreach($excludes as $excludedId){
                abort_if(in_array($excludedId,$selectedIds,true),422,'Die Option „'.$option->name.'“ kann mit einer gewählten Zusatzoption nicht kombiniert werden.');
            }
            abort_if($minimumDuration>0 && $duration<$minimumDuration,422,'Die Option „'.$option->name.'“ benötigt mindestens '.$minimumDuration.' Tage Laufzeit.');
        }

        return ['total'=>round($total,2),'duration_days'=>$duration,'proofs_per_day'=>$proofsPerDay];
    }
}
