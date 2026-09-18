<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        if(!$request->session()->has('2fa_challenge_id')) return redirect()->route('login');
        return view('auth.two-factor');
    }

    public function verify(Request $request)
    {
        $data=$request->validate(['code'=>['required','digits:6']]);
        $challenge=LoginChallenge::with('user')->find($request->session()->get('2fa_challenge_id'));

        if(
            !$challenge ||
            $challenge->user_id!==(int)$request->session()->get('2fa_user_id') ||
            $challenge->used_at ||
            $challenge->expires_at->isPast() ||
            !Hash::check($data['code'],$challenge->code_hash)
        ){
            return back()->withErrors(['code'=>'Der Sicherheitscode ist ungültig oder abgelaufen.']);
        }

        $challenge->update(['used_at'=>now()]);
        $remember=(bool)$request->session()->pull('2fa_remember',false);
        Auth::login($challenge->user,$remember);
        $request->session()->forget(['2fa_user_id','2fa_challenge_id']);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function resend(Request $request)
    {
        $userId=(int)$request->session()->get('2fa_user_id');
        abort_unless($userId,403);

        $old=LoginChallenge::where('user_id',$userId)->whereNull('used_at')->latest()->first();
        abort_if($old && $old->created_at->gt(now()->subMinute()),429,'Bitte warte vor dem erneuten Versand.');

        $user=\App\Models\User::findOrFail($userId);
        $code=(string)random_int(100000,999999);
        $challenge=LoginChallenge::create([
            'user_id'=>$user->id,
            'code_hash'=>Hash::make($code),
            'ip_address'=>$request->ip(),
            'expires_at'=>now()->addMinutes(10),
        ]);
        $request->session()->put('2fa_challenge_id',$challenge->id);

        Mail::raw(
            "Dein Wear&Earn Sicherheitscode lautet: {$code}\n\nDer Code ist 10 Minuten gültig.",
            fn($message)=>$message->to($user->email)->subject('Wear&Earn Sicherheitscode')
        );

        return back()->with('success','Ein neuer Sicherheitscode wurde versendet.');
    }
}
