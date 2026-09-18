<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliabilityEvent extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'metadata'=>'array',
            'occurred_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function restriction(): BelongsTo { return $this->belongsTo(UserRestriction::class); }
}
