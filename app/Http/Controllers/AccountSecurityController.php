<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AccountSecurityController extends Controller
{
    public function updateEmail(Request $request)
    {
        $user=$request->user();

        $data=$request->validate([
            'current_password'=>['required','current_password'],
            'email'=>['required','email','max:255','unique:users,email,'.$user->id],
        ]);

        if(mb_strtolower($data['email'])===mb_strtolower($user->email)){
            return back()->with('success','Die E-Mail-Adresse wurde nicht geändert.');
        }

        $user->update(['email'=>$data['email']]);
        $user->forceFill(['email_verified_at'=>null])->save();

        try{
            $user->sendEmailVerificationNotification();
        }catch(\Throwable $e){
            Log::warning('Email change verification could not be sent',[
                'user_id'=>$user->id,
                'error'=>$e->getMessage(),
            ]);

            return back()
                ->with('success','Die E-Mail-Adresse wurde geändert.')
                ->withErrors(['email'=>'Die Bestätigungs-E-Mail konnte aktuell nicht versendet werden. Du kannst sie im Verifizierungsbereich erneut anfordern.']);
        }

        return back()->with('success','Die E-Mail-Adresse wurde geändert. Bitte bestätige die neue Adresse.');
    }

    public function updatePassword(Request $request)
    {
        $data=$request->validate([
            'current_password'=>['required','current_password'],
            'password'=>['required','string','min:12','confirmed'],
        ]);

        Auth::logoutOtherDevices($data['current_password']);

        $request->user()->forceFill([
            'password'=>Hash::make($data['password']),
            'remember_token'=>Str::random(60),
        ])->save();

        $request->session()->regenerate();

        return back()->with('success','Das Passwort wurde geändert. Andere Sitzungen wurden abgemeldet.');
    }
}
