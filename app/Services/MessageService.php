<?php
namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageService
{
    public function __construct(private NotificationService $notifications) {}

    public function create(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $attachment=null): Message
    {
        abort_unless($conversation->order_id,422,'Nachrichten sind nur innerhalb eines Auftrags zulässig.');

        $attributes=[
            'user_id'=>$sender->id,
            'body'=>trim((string)$body),
        ];

        if($attachment){
            $original=$attachment->getClientOriginalName();
            $mime=$attachment->getMimeType() ?: 'application/octet-stream';
            abort_unless(str_starts_with($mime,'image/') || $mime==='application/pdf',422,'Als Anhang sind nur Bilder oder PDF-Dateien erlaubt.');

            $folder='conversation-'.$conversation->id;
            $extension=strtolower($attachment->getClientOriginalExtension() ?: ($mime==='application/pdf'?'pdf':'jpg'));
            $path=$attachment->storeAs($folder,Str::uuid().'.'.$extension,'messages');

            $attributes += [
                'attachment_path'=>$path,
                'attachment_original_name'=>$original,
                'attachment_mime'=>$mime,
                'attachment_size'=>$attachment->getSize(),
                'attachment_sha256'=>hash_file('sha256',Storage::disk('messages')->path($path)),
            ];
        }

        abort_if($attributes['body']==='' && empty($attributes['attachment_path']),422,'Bitte Nachricht oder Anhang angeben.');

        $message=$conversation->messages()->create($attributes);
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
            $orderNumber=$conversation->order?->order_number ?: (string)$conversation->order_id;
            $this->notifications->send(
                $recipient,
                'order_message',
                'Neue Nachricht zu Auftrag #'.$orderNumber,
                'Im auftragsbezogenen Nachrichtenbereich liegt eine neue Nachricht vor.',
                $url,
                ['order_id'=>$conversation->order_id,'conversation_id'=>$conversation->id]
            );
        }

        return $message;
    }
}
