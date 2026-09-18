<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'shipped_at'=>'datetime',
            'ownership_transferred_at'=>'datetime',
            'risk_transferred_at'=>'datetime',
            'delivered_at'=>'datetime',
            'resubmit_due_at'=>'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function evidences(): HasMany { return $this->hasMany(ShipmentEvidence::class)->orderBy('id'); }
}
