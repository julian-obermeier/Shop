<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class WalletAccount extends Model { protected $guarded=[]; public function entries(): HasMany { return $this->hasMany(WalletLedgerEntry::class); } public function balance(string $bucket): float { return (float) $this->entries()->where('bucket',$bucket)->sum('amount'); } }
