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
        ];
    }

    public function orderDay(): BelongsTo { return $this->belongsTo(OrderDay::class); }
    public function challenge(): BelongsTo { return $this->belongsTo(ProofChallenge::class,'proof_challenge_id'); }
}
