<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'requested_at'=>'datetime',
            'fulfillment_due_at'=>'datetime',
            'requested_shipping_cost'=>'decimal:2',
            'shipping_cost_paid_at'=>'datetime',
            'returned_at'=>'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
