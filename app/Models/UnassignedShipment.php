<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnassignedShipment extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'shipping_date'=>'date',
            'received_at'=>'datetime',
        ];
    }

    public function recorder(): BelongsTo { return $this->belongsTo(User::class,'recorded_by'); }
}
