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


    protected static function booted(): void
    {
        static::updating(function(self $order){
            foreach(['order_number','user_id','offer_snapshot'] as $attribute){
                if($order->isDirty($attribute)){
                    throw new \LogicException('Der ursprüngliche Auftragssnapshot ist unveränderlich.');
                }
            }
        });

        static::deleting(function(){
            throw new \LogicException('Aufträge dürfen nicht gelöscht werden.');
        });
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
        if(in_array($this->status,['precheck','precheck_resubmit','approved','waiting_start','active'],true)) return true;
        if($this->status==='paused') return $this->execution_completed_at===null;
        return false;
    }

    public function scopeCountsAgainstPersonalLimit($query)
    {
        return $query->where(function($q){
            $q->whereIn('status',['precheck','precheck_resubmit','approved','waiting_start','active'])
                ->orWhere(function($paused){
                    $paused->where('status','paused')->whereNull('execution_completed_at');
                });
        });
    }

    public function executionProofsAccepted(): bool
    {
        $hasAcceptedStart=$this->days()
            ->where('day_number',0)
            ->where('status','accepted')
            ->exists();

        if(!$hasAcceptedStart) return false;

        $requiredDays=(int)data_get($this->offer_snapshot,'duration_days',1);
        $acceptedDays=$this->days()
            ->where('series_number',$this->series_number)
            ->where('day_number','>',0)
            ->where('counts_toward_series',true)
            ->where('status','accepted')
            ->count();

        return $acceptedDays >= $requiredDays;
    }

    public function readyForFinalInspection(): bool
    {
        if(!$this->execution_completed_at || !$this->executionProofsAccepted()) return false;
        if(!$this->shipment || $this->shipment->review_status!=='accepted') return false;
        if(!$this->received_at || !$this->goodsReceipt || !$this->goodsReceipt->complete) return false;

        return true;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status,['completed','cancelled','rejected','request_rejected','not_started'],true);
    }
}
