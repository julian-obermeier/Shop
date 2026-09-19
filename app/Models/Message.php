<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded=[];

    protected function casts(): array
    {
        return ['read_at'=>'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function(self $message){
            foreach([
                'conversation_id','user_id','body','attachment_path','attachment_original_name',
                'attachment_mime','attachment_size','attachment_sha256'
            ] as $attribute){
                if($message->isDirty($attribute)){
                    throw new \LogicException('Gesendete Nachrichten und Anhänge sind unveränderlich.');
                }
            }
        });

        static::deleting(function(){
            throw new \LogicException('Gesendete Nachrichten dürfen nicht gelöscht werden.');
        });
    }

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
