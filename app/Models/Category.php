<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Category extends Model { protected $guarded=[]; protected function casts(): array { return ['active'=>'boolean']; } public function offers(): HasMany { return $this->hasMany(Offer::class); } }
