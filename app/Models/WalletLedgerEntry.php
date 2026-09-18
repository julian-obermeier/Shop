<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WalletLedgerEntry extends Model { protected $guarded=[]; protected function casts(): array { return ['amount'=>'decimal:2','metadata'=>'array']; } }
