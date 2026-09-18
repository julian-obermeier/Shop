<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Conversation extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['last_message_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }
}
