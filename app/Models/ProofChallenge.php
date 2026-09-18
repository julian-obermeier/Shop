<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProofChallenge extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['expires_at'=>'datetime','used_at'=>'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderDay(): BelongsTo { return $this->belongsTo(OrderDay::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isUsable(): bool
    {
        return !$this->used_at && $this->expires_at && $this->expires_at->isFuture();
    }
}
