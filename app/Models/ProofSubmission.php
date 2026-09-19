<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProofSubmission extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'reviewed_at'=>'datetime',
            'purged_at'=>'datetime',
            'proof_code_expires_at'=>'datetime',
            'resubmit_due_at'=>'datetime',
            'extra_retry_granted'=>'boolean',
            'proof_data'=>'array',
        ];
    }


    protected static function booted(): void
    {
        static::updating(function(self $proof){
            $immutable=[
                'order_day_id','user_id','type','window_key','text_value','proof_data',
                'proof_code','proof_code_expires_at','proof_challenge_id',
                'storage_path','original_name','mime_type','file_size','sha256','retry_number',
                'purged_at',
            ];

            foreach($immutable as $attribute){
                if($proof->isDirty($attribute)){
                    throw new \LogicException('Eingereichte Nachweisdaten und Nachweisdateien sind unveränderlich.');
                }
            }
        });

        static::deleting(function(){
            throw new \LogicException('Eingereichte Nachweise dürfen nicht gelöscht werden.');
        });
    }

    public function orderDay(): BelongsTo { return $this->belongsTo(OrderDay::class); }
    public function challenge(): BelongsTo { return $this->belongsTo(ProofChallenge::class,'proof_challenge_id'); }
}
