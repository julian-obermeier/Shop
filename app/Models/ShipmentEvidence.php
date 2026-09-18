<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvidence extends Model
{
    protected $table='shipment_evidences';
    protected $guarded=[];

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
