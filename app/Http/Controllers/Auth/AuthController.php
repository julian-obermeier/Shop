<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentConsent;
use App\Models\User;
use App\Models\WalletAccount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function showLogin(){ return view('auth.login'); }

    public function login(Request $request)
    {
        $data=$request->validate([
            'login'=>['required','string','max:255'],
            'password'=>['required','string'],
        ]);

        $login=trim($data['login']);
        $field=filter_var($login,FILTER_VALIDATE_EMAIL)?'email':'username';

        if(!Auth::attempt([$field=>$login,'password'=>$data['password']],$request->boolean('remember'))){
            return back()->withErrors(['login'=>'E-Mail-Adresse/Benutzername oder Passwort ist falsch.'])->onlyInput('login');
        }

        $request->session()->regenerate();
        $user=$request->user();

        if($user->status!=='active'){
            Auth::logout();
            return back()->withErrors(['login'=>'Dieses Konto ist derzeit nicht aktiv.']);
        }

        return redirect()->intended($user->isAdmin()?route('admin.dashboard'):route('dashboard'));
    }

    public function showRegister()
    {
        $documents=Document::where('active',true)
            ->where('requires_consent',true)
            ->with(['versions'=>fn($q)=>$q->where('active',true)->whereNotNull('published_at')->latest('published_at')])
            ->orderBy('title')
            ->get()
            ->filter(fn($document)=>$document->versions->isNotEmpty())
            ->values();

        return view('auth.register',compact('documents'));
    }

    public function register(Request $request)
    {
        $data=$request->validate([
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'birth_date'=>['required','date','before_or_equal:'.now()->subYears(18)->toDateString()],
            'email'=>['required','email','max:255','unique:users,email'],
            'password'=>['required','string','min:12','confirmed'],
            'terms'=>['accepted'],
            'adult'=>['accepted'],
        ],[
            'birth_date.before_or_equal'=>'Die Plattform ist ausschließlich für volljährige Personen vorgesehen.',
        ]);

        $ip=$request->ip();

        $user=DB::transaction(function() use($data,$ip){
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

            $documents=Document::where('active',true)
                ->where('requires_consent',true)
                ->with(['versions'=>fn($q)=>$q->where('active',true)->whereNotNull('published_at')->latest('published_at')])
                ->get();

            foreach($documents as $document){
                $version=$document->versions->first();
                if(!$version) continue;

                DocumentConsent::create([
                    'document_version_id'=>$version->id,
                    'user_id'=>$user->id,
                    'ip_address'=>$ip,
                    'consented_at'=>now(),
                ]);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        try{
            $user->sendEmailVerificationNotification();
        }catch(\Throwable $e){
            Log::warning('Initial verification email could not be sent',[
                'user_id'=>$user->id,
                'error'=>$e->getMessage(),
            ]);
        }

        return redirect()->route('verification.notice');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
