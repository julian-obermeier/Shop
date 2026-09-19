<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['published_at'=>'datetime','active'=>'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function(self $version){
            foreach(['document_id','version','content','published_at'] as $attribute){
                if($version->isDirty($attribute)){
                    throw new \LogicException('Veröffentlichte Dokumentfassungen sind inhaltlich unveränderlich; Änderungen benötigen eine neue Version.');
                }
            }
        });

        static::deleting(function(self $version){
            if($version->consents()->exists()){
                throw new \LogicException('Dokumentfassungen mit Einwilligungsnachweisen dürfen nicht gelöscht werden.');
            }
        });
    }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function consents(): HasMany { return $this->hasMany(DocumentConsent::class); }
}
