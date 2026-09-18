<?php
namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        $data=$request->validate([
            'endpoint'=>['required','url','max:2048'],
            'keys.p256dh'=>['required','string','max:255'],
            'keys.auth'=>['required','string','max:255'],
            'contentEncoding'=>['nullable','string','max:30'],
        ]);

        $hash=hash('sha256',$data['endpoint']);

        PushSubscription::updateOrCreate(
            ['endpoint_hash'=>$hash],
            [
                'user_id'=>$request->user()->id,
                'endpoint'=>$data['endpoint'],
                'public_key'=>$data['keys']['p256dh'],
                'auth_token'=>$data['keys']['auth'],
                'content_encoding'=>$data['contentEncoding']??null,
                'user_agent'=>$request->userAgent(),
                'last_used_at'=>now(),
            ]
        );

        return response()->json(['ok'=>true]);
    }

    public function destroy(Request $request)
    {
        $data=$request->validate([
            'endpoint'=>['required','url','max:2048'],
        ]);

        PushSubscription::where('user_id',$request->user()->id)
            ->where('endpoint_hash',hash('sha256',$data['endpoint']))
            ->delete();

        return response()->json(['ok'=>true]);
    }
}
