<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Document extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['requires_consent'=>'boolean','active'=>'boolean']; }
    public function versions(): HasMany { return $this->hasMany(DocumentVersion::class); }
    public function currentVersion(): ?DocumentVersion { return $this->versions()->where('active',true)->whereNotNull('published_at')->latest('published_at')->first(); }
}
