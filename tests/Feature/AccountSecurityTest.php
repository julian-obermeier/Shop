<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_change_requires_current_password_and_resets_verification(): void
    {
        Notification::fake();

        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>'old@example.test',
            'password'=>Hash::make('CurrentPassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);
        $user->forceFill(['email_verified_at'=>now()])->save();

        $wrong=$this->actingAs($user)->put(route('profile.email'),[
            'email'=>'new@example.test',
            'current_password'=>'wrong-password',
        ]);
        $wrong->assertSessionHasErrors('current_password');

        $success=$this->actingAs($user)->put(route('profile.email'),[
            'email'=>'new@example.test',
            'current_password'=>'CurrentPassword123!',
        ]);
        $success->assertRedirect();

        $user->refresh();
        $this->assertSame('new@example.test',$user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_password_change_replaces_password_hash(): void
    {
        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>'security@example.test',
            'password'=>Hash::make('CurrentPassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);

        $response=$this->actingAs($user)->put(route('profile.password'),[
            'current_password'=>'CurrentPassword123!',
            'password'=>'NewSecurePassword456!',
            'password_confirmation'=>'NewSecurePassword456!',
        ]);

        $response->assertRedirect();
        $this->assertTrue(Hash::check('NewSecurePassword456!',$user->fresh()->password));
    }
    public function test_security_headers_allow_same_origin_camera_but_keep_other_sensitive_features_disabled(): void
    {
        $user=\App\Models\User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Kamera',
            'birth_date'=>'1995-01-01',
            'email'=>'camera-policy@example.test',
            'password'=>'VerySecurePassword123!',
            'status'=>'active',
        ]);
        $user->forceFill(['email_verified_at'=>now()])->save();
        $user->profile()->create();

        $response=$this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $policy=(string)$response->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=(self)',$policy);
        $this->assertStringContainsString('microphone=()',$policy);
        $this->assertStringContainsString('geolocation=()',$policy);
        $this->assertStringContainsString('payment=()',$policy);
        $this->assertStringNotContainsString('camera=()',$policy);
    }


}
