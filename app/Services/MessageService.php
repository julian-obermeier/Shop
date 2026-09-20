<?php
namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class MessageService
{
    public function __construct(private NotificationService $notifications) {}

    public function create(Conversation $conversation, User $sender, string $body): Message
    {
        abort_unless($conversation->order_id,422,'Nachrichten sind nur innerhalb eines Auftrags zulässig.');
        $body=trim($body);
        abort_if($body==='',422,'Bitte eine Nachricht eingeben.');

        $message=$conversation->messages()->create([
            'user_id'=>$sender->id,
            'body'=>$body,
            'attachment_path'=>null,
        ]);
        $conversation->update(['last_message_at'=>now(),'status'=>'open']);

        $conversation->loadMissing('user','order');
        if($sender->role==='admin'){
            $recipient=$conversation->user;
            $url=$recipient ? route('messages.show',$conversation) : null;
        } else {
            $recipient=User::where('role','admin')->where('status','active')->first();
            $url=$recipient ? route('admin.messages.show',$conversation) : null;
        }

        if($recipient && $recipient->id!==$sender->id){
            $this->notifications->send(
                $recipient,
                'order_message',
                'Neue Nachricht zu Auftrag #'.($conversation->order?->order_number ?: $conversation->order_id),
                'Im Auftragschat liegt eine neue Textnachricht vor.',
                $url,
                ['order_id'=>$conversation->order_id,'conversation_id'=>$conversation->id]
            );
        }

        return $message;
    }
}
