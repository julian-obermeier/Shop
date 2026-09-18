<?php
namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\PushService;

class NotificationController extends Controller
{
    public function index(PushService $push)
    {
        $notifications=request()->user()->userNotifications()->latest()->paginate(30);
        $pushConfigured=$push->configured();
        $vapidPublicKey=(string)config('webpush.public_key');

        return view('notifications.index',compact('notifications','pushConfigured','vapidPublicKey'));
    }

    public function read(UserNotification $notification)
    {
        abort_unless($notification->user_id===request()->user()->id,403);
        $notification->update(['read_at'=>now()]);

        return $notification->url ? redirect($notification->url) : back();
    }

    public function readAll()
    {
        request()->user()->userNotifications()->whereNull('read_at')->update(['read_at'=>now()]);

        return back()->with('success','Alle Benachrichtigungen wurden als gelesen markiert.');
    }
}
