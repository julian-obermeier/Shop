<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DocumentVersion extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['published_at'=>'datetime','active'=>'boolean']; }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function consents(): HasMany { return $this->hasMany(DocumentConsent::class); }
}
