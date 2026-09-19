<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutRequest extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2',
            'destination'=>'encrypted:array',
            'processing_date'=>'date',
            'approved_at'=>'datetime',
            'payment_executed_at'=>'datetime',
            'completed_at'=>'datetime',
            'reopened_at'=>'datetime',
            'paid_at'=>'datetime',
        ];
    }


    protected static function booted(): void
    {
        static::updating(function(self $payout){
            foreach(['payout_number','user_id','amount','method','destination','processing_date'] as $attribute){
                if($payout->isDirty($attribute)){
                    throw new \LogicException('Der Auszahlungsantrag ist ein unveränderlicher Snapshot; Ziel- und Betragsdaten dürfen nach Antragstellung nicht geändert werden.');
                }
            }
        });

        static::deleting(function(){
            throw new \LogicException('Auszahlungsanträge dürfen nicht gelöscht werden.');
        });
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isOpen(): bool
    {
        return in_array($this->status,['requested','review','approved','payment_executed','failed'],true);
    }
}
