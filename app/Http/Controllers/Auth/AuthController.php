<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginChallenge;
use App\Models\User;
use App\Models\WalletAccount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function showLogin(){ return view('auth.login'); }

    public function login(Request $request)
    {
        $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);

        if(!Auth::attempt($credentials,$request->boolean('remember'))){
            return back()->withErrors(['email'=>'E-Mail-Adresse oder Passwort ist falsch.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $user=$request->user();

        if($user->status!=='active'){
            Auth::logout();
            return back()->withErrors(['email'=>'Dieses Konto ist derzeit nicht aktiv.']);
        }

        if($user->isAdmin()){
            $remember=$request->boolean('remember');
            $code=(string)random_int(100000,999999);

            $challenge=LoginChallenge::create([
                'user_id'=>$user->id,
                'code_hash'=>Hash::make($code),
                'ip_address'=>$request->ip(),
                'expires_at'=>now()->addMinutes(10),
            ]);

            Auth::logout();
            $request->session()->put([
                '2fa_user_id'=>$user->id,
                '2fa_challenge_id'=>$challenge->id,
                '2fa_remember'=>$remember,
            ]);

            Mail::raw(
                "Dein Wear&Earn Sicherheitscode lautet: {$code}\n\nDer Code ist 10 Minuten gültig.",
                fn($message)=>$message->to($user->email)->subject('Wear&Earn Sicherheitscode')
            );

            return redirect()->route('two-factor.show');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(){ return view('auth.register'); }

    public function register(Request $request)
    {
        $data=$request->validate([
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'birth_date'=>['required','date','before_or_equal:'.now()->subYears(18)->toDateString()],
            'email'=>['required','email','max:255','unique:users,email'],
            'password'=>['required','string','min:10','confirmed'],
            'terms'=>['accepted'],
            'adult'=>['accepted'],
        ],['birth_date.before_or_equal'=>'Die Plattform ist ausschließlich für volljährige Personen vorgesehen.']);

        $user=DB::transaction(function() use($data){
            $user=User::create([
                'role'=>'provider',
                'first_name'=>$data['first_name'],
                'last_name'=>$data['last_name'],
                'birth_date'=>Carbon::parse($data['birth_date']),
                'email'=>$data['email'],
                'password'=>$data['password'],
                'status'=>'active',
            ]);
            $user->profile()->create();
            WalletAccount::create(['user_id'=>$user->id]);
            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
