<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_minor_cannot_register(): void
    {
        Notification::fake();

        $response=$this->post(route('register.submit'),[
            'first_name'=>'Test',
            'last_name'=>'Person',
            'birth_date'=>now()->subYears(17)->toDateString(),
            'email'=>'minor@example.test',
            'password'=>'VerySecurePassword123!',
            'password_confirmation'=>'VerySecurePassword123!',
            'terms'=>'1',
            'adult'=>'1',
        ]);

        $response->assertSessionHasErrors('birth_date');
        $this->assertDatabaseMissing('users',['email'=>'minor@example.test']);
    }

    public function test_admin_can_login_with_username_or_email(): void
    {
        $admin=User::create([
            'role'=>'admin',
            'username'=>'admin.test',
            'first_name'=>'Admin',
            'last_name'=>'Test',
            'birth_date'=>'1970-01-01',
            'email'=>'admin-login@example.test',
            'password'=>'VerySecurePassword123!',
            'status'=>'active',
        ]);
        $admin->forceFill(['email_verified_at'=>now()])->save();

        $this->post(route('login.submit'),[
            'login'=>'admin.test',
            'password'=>'VerySecurePassword123!',
        ])->assertRedirect(route('admin.dashboard'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->post(route('login.submit'),[
            'login'=>'admin-login@example.test',
            'password'=>'VerySecurePassword123!',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_exactly_one_admin_account_is_enforced_by_model(): void
    {
        $admin=User::create([
            'role'=>'admin',
            'username'=>'single.admin',
            'first_name'=>'Admin',
            'last_name'=>'One',
            'birth_date'=>'1970-01-01',
            'email'=>'single-admin@example.test',
            'password'=>'VerySecurePassword123!',
            'status'=>'active',
        ]);

        try{
            User::create([
                'role'=>'admin',
                'username'=>'second.admin',
                'first_name'=>'Admin',
                'last_name'=>'Two',
                'birth_date'=>'1970-01-01',
                'email'=>'second-admin@example.test',
                'password'=>'VerySecurePassword123!',
                'status'=>'active',
            ]);
            $this->fail('Ein zweites Admin-Konto konnte angelegt werden.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('genau ein Admin-Konto',$e->getMessage());
        }

        try{
            $admin->update(['role'=>'provider']);
            $this->fail('Das einzige Admin-Konto konnte in eine andere Rolle umgewandelt werden.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('nicht in eine andere Rolle',$e->getMessage());
        }

        try{
            $admin->delete();
            $this->fail('Das einzige Admin-Konto konnte gelöscht werden.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('nicht gelöscht',$e->getMessage());
        }

        $this->assertSame(1,User::where('role','admin')->count());
    }


    public function test_adult_registration_creates_unverified_email_account(): void
    {
        Notification::fake();

        $response=$this->post(route('register.submit'),[
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>now()->subYears(25)->toDateString(),
            'email'=>'anna@example.test',
            'password'=>'VerySecurePassword123!',
            'password_confirmation'=>'VerySecurePassword123!',
            'terms'=>'1',
            'adult'=>'1',
        ]);

        $response->assertRedirect(route('verification.notice'));

        $user=User::where('email','anna@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('provider',$user->role);
        $this->assertDatabaseHas('wallet_accounts',['user_id'=>$user->id]);
    }
}
