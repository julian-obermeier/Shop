<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PayoutRequest extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['amount'=>'decimal:2','destination'=>'encrypted:array','paid_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
