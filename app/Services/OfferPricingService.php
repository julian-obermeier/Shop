<?php
namespace App\Services;
use App\Models\Offer;
use Illuminate\Support\Collection;
class OfferPricingService {
    public function calculate(Offer $offer, Collection $options): array {
        $total = (float) $offer->base_compensation;
        $duration = (int) $offer->duration_days;
        $proofsPerDay = (int) $offer->proofs_per_day;
        foreach ($options as $option) {
            $total += (float) $option->price_delta;
            $duration += (int) $option->extra_duration_days;
            $proofsPerDay += (int) $option->extra_proofs_per_day;
        }
        return ['total'=>round($total,2),'duration_days'=>$duration,'proofs_per_day'=>$proofsPerDay];
    }
}
