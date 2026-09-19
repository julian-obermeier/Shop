<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['amount'=>'decimal:2','metadata'=>'array'];
    }

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Wallet-Ledger-Einträge sind unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Wallet-Ledger-Einträge dürfen nicht gelöscht werden.');
        });
    }
}
