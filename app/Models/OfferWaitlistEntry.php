<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferWaitlistEntry extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'reserved_at'=>'datetime',
            'reservation_expires_at'=>'datetime',
            'planned_start_date'=>'date',
        ];
    }

    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
