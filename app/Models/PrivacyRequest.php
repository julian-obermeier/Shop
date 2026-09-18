<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyRequest extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['reviewed_at'=>'datetime','completed_at'=>'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }
}
