<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AuditLog extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['before'=>'array','after'=>'array']; }

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Audit-Log-Einträge sind unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Audit-Log-Einträge dürfen nicht gelöscht werden.');
        });
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
