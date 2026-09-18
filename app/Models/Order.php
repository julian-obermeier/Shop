<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Order extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['offer_snapshot'=>'array','compensation_total'=>'decimal:2','start_date'=>'date','end_date'=>'date','accepted_at'=>'datetime','received_at'=>'datetime','completed_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }
    public function options(): HasMany { return $this->hasMany(OrderOption::class); }
    public function days(): HasMany { return $this->hasMany(OrderDay::class)->orderBy('day_number'); }
    public function statusHistory(): HasMany { return $this->hasMany(OrderStatusHistory::class); }
}
