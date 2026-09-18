<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsInspection extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'categories'=>'array',
            'extra_results'=>'array',
            'base_percentage'=>'decimal:2',
            'calculated_compensation'=>'decimal:2',
            'reviewed_at'=>'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }
}
