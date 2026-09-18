<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OrderPrecheck extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['answers'=>'array','submitted_at'=>'datetime','reviewed_at'=>'datetime']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
