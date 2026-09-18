<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationController extends Controller
{
    public function notice()
    {
        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request)
    {
        if(!$request->user()->hasVerifiedEmail()){
            $request->fulfill();
        }

        return redirect()->route('dashboard')->with('success','Deine E-Mail-Adresse wurde bestätigt.');
    }

    public function send(Request $request)
    {
        if($request->user()->hasVerifiedEmail()){
            return back()->with('success','Deine E-Mail-Adresse ist bereits bestätigt.');
        }

        try{
            $request->user()->sendEmailVerificationNotification();
        }catch(\Throwable $e){
            Log::warning('Verification email could not be sent',[
                'user_id'=>$request->user()->id,
                'error'=>$e->getMessage(),
            ]);
            return back()->withErrors(['email'=>'Die Verifizierungs-E-Mail konnte aktuell nicht versendet werden.']);
        }

        return back()->with('success','Eine neue Verifizierungs-E-Mail wurde versendet.');
    }
}
