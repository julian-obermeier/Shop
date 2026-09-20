<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDay extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'date'=>'date',
            'plan'=>'array',
            'counts_toward_series'=>'boolean',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function proofs(): HasMany { return $this->hasMany(ProofSubmission::class); }
}
