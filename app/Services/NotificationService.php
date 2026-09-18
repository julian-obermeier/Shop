<?php
namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function send(User $user, string $type, string $title, string $body, ?string $url = null, array $data = []): UserNotification
    {
        $notification=UserNotification::create([
            'user_id'=>$user->id,
            'type'=>$type,
            'title'=>$title,
            'body'=>$body,
            'url'=>$url,
            'data'=>$data ?: null,
        ]);

        if(Setting::valueOf('email_notifications_enabled',true) && filter_var($user->email,FILTER_VALIDATE_EMAIL)){
            try{
                Mail::raw(
                    $title."\n\n".$body.($url?"\n\n".$url:''),
                    fn($message)=>$message->to($user->email)->subject('Wear&Earn · '.$title)
                );
            }catch(\Throwable $e){
                Log::warning('Notification email could not be sent',['user_id'=>$user->id,'type'=>$type,'error'=>$e->getMessage()]);
            }
        }

        return $notification;
    }
}
