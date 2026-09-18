<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'base_compensation'=>'decimal:2',
            'requires_precheck'=>'boolean',
            'is_sock_wearing'=>'boolean',
            'active'=>'boolean',
            'rules'=>'array',
            'proof_requirements'=>'array',
            'inspection_config'=>'array',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function options(): HasMany { return $this->hasMany(OfferOption::class)->orderBy('sort_order'); }
    public function fields(): HasMany { return $this->hasMany(OfferField::class)->orderBy('sort_order'); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function waitlistEntries(): HasMany { return $this->hasMany(OfferWaitlistEntry::class); }

    public function scopePublished($query)
    {
        return $query->where('active',true);
    }

    public function activeCapacityUsage(): int
    {
        return $this->orders()
            ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
            ->count();
    }

    public function hasFreeCapacity(): bool
    {
        return !$this->capacity || $this->activeCapacityUsage() < (int)$this->capacity;
    }
}
