<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WalletPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_reserve_available_balance_for_payout(): void
    {
        Mail::fake();

        $user=$this->makeVerifiedUser('wallet@example.test');
        $wallet=WalletAccount::create(['user_id'=>$user->id]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>50,
            'description'=>'Testguthaben',
        ]);

        $response=$this->actingAs($user)->post(route('wallet.payout'),[
            'amount'=>20,
            'iban'=>'DE89370400440532013000',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('payout_requests',[
            'user_id'=>$user->id,
            'amount'=>20,
            'status'=>'requested',
        ]);
        $this->assertSame(30.0,$wallet->balance('available'));
        $this->assertSame(20.0,$wallet->balance('payout_pending'));
    }

    public function test_payout_above_available_balance_is_rejected(): void
    {
        Mail::fake();

        $user=$this->makeVerifiedUser('wallet2@example.test');
        $wallet=WalletAccount::create(['user_id'=>$user->id]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>15,
            'description'=>'Testguthaben',
        ]);

        $response=$this->actingAs($user)->post(route('wallet.payout'),[
            'amount'=>30,
            'iban'=>'DE89370400440532013000',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payout_requests',0);
    }

    private function makeVerifiedUser(string $email): User
    {
        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>$email,
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);
        $user->forceFill(['email_verified_at'=>now()])->save();
        return $user;
    }
}
