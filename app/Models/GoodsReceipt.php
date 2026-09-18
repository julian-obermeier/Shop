<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class GoodsReceipt extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['complete'=>'boolean','received_at'=>'datetime']; }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class,'received_by'); }
}
