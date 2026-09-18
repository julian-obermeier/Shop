<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UserRestriction extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','active'=>'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class,'issued_by'); }
    public function scopeCurrent($query){ return $query->where('active',true)->where('starts_at','<=',now())->where(fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>=',now())); }
}
