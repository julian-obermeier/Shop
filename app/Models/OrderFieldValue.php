<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFieldValue extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['field_snapshot'=>'array'];
    }

    protected static function booted(): void
    {
        static::updating(function(){
            throw new \LogicException('Auftragsspezifische Auswahl- und Eingabewerte sind nach der Anfrage unveränderlich.');
        });

        static::deleting(function(){
            throw new \LogicException('Auftragsspezifische Auswahl- und Eingabewerte dürfen nicht gelöscht werden.');
        });
    }

    public function anonymizeForDeletedUser(): void
    {
        $this->forceFill(['value'=>null])->saveQuietly();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offerField(): BelongsTo
    {
        return $this->belongsTo(OfferField::class);
    }
}
