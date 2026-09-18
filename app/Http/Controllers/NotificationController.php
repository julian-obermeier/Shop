<?php
namespace App\Http\Controllers;

use App\Models\UserNotification;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications=request()->user()->userNotifications()->latest()->paginate(30);
        $pushConfigured=app(\\App\\Services\\PushService::class)->configured();
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
