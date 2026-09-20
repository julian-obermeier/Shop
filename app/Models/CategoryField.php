<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryField extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'options'=>'array',
            'required'=>'boolean',
            'active'=>'boolean',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
}
