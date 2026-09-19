<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    protected $table='order_status_history';
    protected $guarded=[];

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Auftragsstatushistorien sind unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Auftragsstatushistorien dürfen nicht gelöscht werden.');
        });
    }
}
