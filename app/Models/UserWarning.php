<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UserWarning extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['expires_at'=>'datetime','acknowledged_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class,'issued_by'); }
}
