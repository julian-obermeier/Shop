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
    public function __construct(private ImageSanitizer $images) {}

    public function create(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $attachment=null): Message
    {
        $attributes=[
            'user_id'=>$sender->id,
            'body'=>trim((string)$body),
        ];

        if($attachment){
            $original=$attachment->getClientOriginalName();
            $mime=$attachment->getMimeType() ?: 'application/octet-stream';
            $folder='conversation-'.$conversation->id;

            if(str_starts_with($mime,'image/')){
                $stored=$this->images->store($attachment,'messages',$folder,2200,2200);
                $path=$stored['path'];
                $mime=$stored['mime'];
                $size=$stored['size'];
            } else {
                abort_unless($mime==='application/pdf',422,'Als Anhang sind nur Bilder oder PDF-Dateien erlaubt.');
                $path=$attachment->storeAs($folder,Str::uuid().'.pdf','messages');
                $size=$attachment->getSize();
            }

            $attributes += [
                'attachment_path'=>$path,
                'attachment_original_name'=>$original,
                'attachment_mime'=>$mime,
                'attachment_size'=>$size,
                'attachment_sha256'=>hash_file('sha256',Storage::disk('messages')->path($path)),
            ];
        }

        abort_if($attributes['body']==='' && empty($attributes['attachment_path']),422,'Bitte Nachricht oder Anhang angeben.');

        $message=$conversation->messages()->create($attributes);
        $conversation->update(['last_message_at'=>now(),'status'=>'open']);

        return $message;
    }
}
