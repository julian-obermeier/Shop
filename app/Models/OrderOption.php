<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderOption extends Model { protected $guarded=[]; protected function casts(): array { return ['price_delta'=>'decimal:2','snapshot'=>'array']; } }
