<?php
namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushService
{
    public function configured(): bool
    {
        return Setting::valueOf('push_notifications_enabled',true)
            && filled(config('webpush.public_key'))
            && filled(config('webpush.private_key'))
            && filled(config('webpush.subject'))
            && class_exists(WebPush::class);
    }

    public function send(User $user, string $title, string $body, ?string $url=null, array $data=[]): void
    {
        if(!$this->configured()) return;

        $subscriptions=$user->pushSubscriptions()->get();
        if($subscriptions->isEmpty()) return;

        $auth=[
            'VAPID'=>[
                'subject'=>(string)config('webpush.subject'),
                'publicKey'=>(string)config('webpush.public_key'),
                'privateKey'=>(string)config('webpush.private_key'),
            ],
        ];

        $webPush=new WebPush($auth,[
            'TTL'=>86400,
            'urgency'=>'high',
            'contentType'=>'application/json',
        ]);

        $payload=json_encode([
            'title'=>$title,
            'body'=>$body,
            'url'=>$url ?: route('notifications.index'),
            'data'=>$data,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        foreach($subscriptions as $stored){
            try{
                $subscriptionData=[
                    'endpoint'=>$stored->endpoint,
                    'keys'=>[
                        'p256dh'=>$stored->public_key,
                        'auth'=>$stored->auth_token,
                    ],
                ];
                if($stored->content_encoding) $subscriptionData['contentEncoding']=$stored->content_encoding;

                $report=$webPush->sendOneNotification(
                    Subscription::create($subscriptionData),
                    $payload
                );

                if($report->isSuccess()){
                    $stored->update(['last_used_at'=>now()]);
                } elseif($report->isSubscriptionExpired()){
                    $stored->delete();
                } else {
                    Log::warning('Web push could not be delivered',[
                        'user_id'=>$user->id,
                        'subscription_id'=>$stored->id,
                        'reason'=>$report->getReason(),
                    ]);
                }
            }catch(\Throwable $e){
                Log::warning('Web push failed',[
                    'user_id'=>$user->id,
                    'subscription_id'=>$stored->id,
                    'error'=>$e->getMessage(),
                ]);
            }
        }
    }
}
