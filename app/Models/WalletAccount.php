<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletAccount extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return [
            'available_balance_override'=>'decimal:2',
            'available_balance_override_at'=>'datetime',
        ];
    }

    public function entries(): HasMany { return $this->hasMany(WalletLedgerEntry::class); }

    public function balance(string $bucket): float
    {
        if($bucket==='available' && $this->available_balance_override!==null && $this->available_balance_override_at){
            $delta=(float)$this->entries()
                ->where('bucket','available')
                ->where('created_at','>',$this->available_balance_override_at)
                ->sum('amount');
            return round((float)$this->available_balance_override+$delta,2);
        }

        return (float)$this->entries()->where('bucket',$bucket)->sum('amount');
    }
}
