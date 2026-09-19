<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentConsent extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['consented_at'=>'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Einwilligungsnachweise sind unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Einwilligungsnachweise dürfen nicht gelöscht werden.');
        });
    }

    public function version(): BelongsTo { return $this->belongsTo(DocumentVersion::class,'document_version_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
