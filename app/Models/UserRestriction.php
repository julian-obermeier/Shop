<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRestriction extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'starts_at'=>'datetime',
            'ends_at'=>'datetime',
            'active'=>'boolean',
            'blocked_offer_ids'=>'array',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class,'issued_by'); }
    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }

    public function scopeCurrent($query)
    {
        return $query->where('active',true)->where('starts_at','<=',now());
    }
}
