<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature='admin:create
        {--email= : E-Mail-Adresse}
        {--username= : Benutzername}
        {--first-name= : Vorname}
        {--last-name= : Nachname}';

    protected $description='Create the single administration account';

    public function handle(): int
    {
        if(User::where('role','admin')->exists()){
            $this->error('Es existiert bereits ein Admin-Konto. Laut MASTERPROMPT ist genau ein Admin-Konto zulässig.');
            return self::FAILURE;
        }

        $email=(string)($this->option('email') ?: $this->ask('E-Mail-Adresse'));
        $username=(string)($this->option('username') ?: $this->ask('Benutzername'));
        $firstName=(string)($this->option('first-name') ?: $this->ask('Vorname'));
        $lastName=(string)($this->option('last-name') ?: $this->ask('Nachname'));

        $password=(string)$this->secret('Passwort (mindestens 12 Zeichen)');
        $confirmation=(string)$this->secret('Passwort wiederholen');

        $validator=Validator::make([
            'email'=>$email,
            'username'=>$username,
            'first_name'=>$firstName,
            'last_name'=>$lastName,
            'password'=>$password,
            'password_confirmation'=>$confirmation,
        ],[
            'email'=>['required','email','max:255','unique:users,email'],
            'username'=>['required','string','min:3','max:80','regex:/^[A-Za-z0-9._-]+$/','unique:users,username'],
            'first_name'=>['required','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'password'=>['required','string','min:12','confirmed'],
        ]);

        if($validator->fails()){
            foreach($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }

        $user=User::create([
            'role'=>'admin',
            'username'=>$username,
            'first_name'=>$firstName,
            'last_name'=>$lastName,
            'birth_date'=>'1970-01-01',
            'email'=>$email,
            'password'=>Hash::make($password),
            'status'=>'active',
        ]);

        $user->forceFill(['email_verified_at'=>now()])->save();

        $this->info('Admin-Konto wurde erfolgreich angelegt: '.$user->username.' / '.$user->email);
        return self::SUCCESS;
    }
}
