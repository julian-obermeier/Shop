<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'public_key'=>'encrypted',
            'auth_token'=>'encrypted',
            'last_used_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
