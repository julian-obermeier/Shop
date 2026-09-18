<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Offer extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['base_compensation'=>'decimal:2','requires_precheck'=>'boolean','active'=>'boolean','rules'=>'array','available_from'=>'datetime','available_until'=>'datetime']; }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function options(): HasMany { return $this->hasMany(OfferOption::class)->orderBy('sort_order'); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function scopePublished($query) { return $query->where('active', true)->where(fn($q)=>$q->whereNull('available_from')->orWhere('available_from','<=',now()))->where(fn($q)=>$q->whereNull('available_until')->orWhere('available_until','>=',now())); }
}
