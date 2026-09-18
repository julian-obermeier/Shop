<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OfferOption extends Model { protected $guarded=[]; protected function casts(): array { return ['price_delta'=>'decimal:2','required'=>'boolean','active'=>'boolean','rules'=>'array']; } public function offer(): BelongsTo { return $this->belongsTo(Offer::class); } }
