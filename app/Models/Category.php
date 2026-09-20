<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'active'=>'boolean',
            'system_template'=>'boolean',
            'config'=>'array',
        ];
    }

    public function parent(): BelongsTo { return $this->belongsTo(self::class,'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class,'parent_id')->orderBy('sort_order')->orderBy('name'); }
    public function offers(): HasMany { return $this->hasMany(Offer::class); }
    public function fields(): HasMany { return $this->hasMany(CategoryField::class)->orderBy('sort_order'); }

    public function isDigital(): bool { return $this->kind==='digital'; }
}
