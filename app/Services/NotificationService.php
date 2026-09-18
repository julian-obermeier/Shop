<?php
namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;

class NotificationService
{
    public function send(User $user, string $type, string $title, string $body, ?string $url = null, array $data = []): UserNotification
    {
        return UserNotification::create([
            'user_id'=>$user->id,
            'type'=>$type,
            'title'=>$title,
            'body'=>$body,
            'url'=>$url,
            'data'=>$data ?: null,
        ]);
    }
}
