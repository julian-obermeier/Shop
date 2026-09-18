<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferField extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['options'=>'array','required'=>'boolean','active'=>'boolean'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
