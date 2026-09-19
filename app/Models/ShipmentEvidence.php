<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvidence extends Model
{
    protected $table='shipment_evidences';
    protected $guarded=[];


    protected static function booted(): void
    {
        static::updating(function(self $evidence){
            $immutable=[
                'shipment_id','user_id','type','storage_path','original_name',
                'mime_type','file_size','sha256','attempt',
            ];

            foreach($immutable as $attribute){
                if($evidence->isDirty($attribute)){
                    throw new \LogicException('Eingereichte Versandnachweise sind unveränderlich.');
                }
            }
        });

        static::deleting(function(){
            throw new \LogicException('Eingereichte Versandnachweise dürfen nicht gelöscht werden.');
        });
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
