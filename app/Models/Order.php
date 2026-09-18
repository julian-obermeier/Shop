<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'offer_snapshot'=>'array',
            'current_requirements'=>'array',
            'compensation_total'=>'decimal:2',
            'final_compensation'=>'decimal:2',
            'proposed_start_date'=>'date',
            'confirmed_start_date'=>'date',
            'activation_date'=>'date',
            'start_date'=>'date',
            'end_date'=>'date',
            'accepted_at'=>'datetime',
            'received_at'=>'datetime',
            'execution_completed_at'=>'datetime',
            'completed_at'=>'datetime',
            'shipping_due_at'=>'datetime',
            'paused_at'=>'datetime',
            'requirements_effective_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }
    public function options(): HasMany { return $this->hasMany(OrderOption::class); }
    public function fieldValues(): HasMany { return $this->hasMany(OrderFieldValue::class); }
    public function days(): HasMany { return $this->hasMany(OrderDay::class)->orderBy('series_number')->orderBy('day_number'); }
    public function statusHistory(): HasMany { return $this->hasMany(OrderStatusHistory::class); }
    public function precheck(): HasOne { return $this->hasOne(OrderPrecheck::class); }
    public function shipment(): HasOne { return $this->hasOne(Shipment::class); }
    public function goodsReceipt(): HasOne { return $this->hasOne(GoodsReceipt::class); }
    public function goodsInspection(): HasOne { return $this->hasOne(GoodsInspection::class); }
    public function returnRequest(): HasOne { return $this->hasOne(ReturnRequest::class); }
    public function conversation(): HasOne { return $this->hasOne(Conversation::class); }
    public function proofChallenges(): HasMany { return $this->hasMany(ProofChallenge::class); }

    public function isSockWearing(): bool
    {
        return (bool)data_get($this->offer_snapshot,'is_sock_wearing',false);
    }

    public function countsAgainstPersonalLimit(): bool
    {
        return in_array($this->status,[
            'approved','waiting_start','awaiting_date_confirmation','active','paused'
        ],true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status,['completed','cancelled','rejected','not_started'],true);
    }
}
