<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFieldValue extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['field_snapshot'=>'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offerField(): BelongsTo
    {
        return $this->belongsTo(OfferField::class);
    }
}
