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

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Endgültig dokumentierte nicht zuordenbare Sendungen sind unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Nicht zuordenbare Sendungen dürfen nach der endgültigen Dokumentation nicht gelöscht werden.');
        });
    }

    public function recorder(): BelongsTo { return $this->belongsTo(User::class,'recorded_by'); }
}
