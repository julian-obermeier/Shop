<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderOption extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['price_delta'=>'decimal:2','snapshot'=>'array'];
    }

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Gewählte Auftragsoptionen sind Bestandteil des unveränderlichen Auftragssnapshots.');
        });

        static::deleting(function(){
            throw new \LogicException('Gewählte Auftragsoptionen dürfen nicht aus einem bestehenden Auftrag gelöscht werden.');
        });
    }
}
