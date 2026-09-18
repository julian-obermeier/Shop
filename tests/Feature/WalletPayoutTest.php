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

    public function test_verified_user_can_reserve_partial_available_balance_for_bank_payout(): void
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
            'method'=>'bank_transfer',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('payout_requests',[
            'user_id'=>$user->id,
            'amount'=>20,
            'status'=>'requested',
            'method'=>'bank_transfer',
        ]);
        $this->assertSame(30.0,$wallet->fresh()->balance('available'));
        $this->assertSame(20.0,$wallet->fresh()->balance('payout_pending'));

        $payout=$user->payouts()->firstOrFail();
        $this->assertSame('DE89370400440532013000',$payout->destination['iban']);
        $this->assertSame('Anna Beispiel',$payout->destination['account_holder']);
    }

    public function test_there_is_no_minimum_payout_other_than_positive_amount(): void
    {
        Mail::fake();

        $user=$this->makeVerifiedUser('small@example.test');
        $wallet=WalletAccount::create(['user_id'=>$user->id]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>1,
            'description'=>'Testguthaben',
        ]);

        $response=$this->actingAs($user)->post(route('wallet.payout'),[
            'amount'=>0.50,
            'method'=>'bank_transfer',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('payout_requests',[
            'user_id'=>$user->id,
            'amount'=>0.50,
            'status'=>'requested',
        ]);
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
            'method'=>'bank_transfer',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payout_requests',0);
    }

    public function test_provider_can_cancel_open_payout_and_reserved_money_becomes_available_again(): void
    {
        Mail::fake();

        $user=$this->makeVerifiedUser('cancel@example.test');
        $wallet=WalletAccount::create(['user_id'=>$user->id]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>25,
            'description'=>'Testguthaben',
        ]);

        $this->actingAs($user)->post(route('wallet.payout'),[
            'amount'=>10,
            'method'=>'bank_transfer',
        ])->assertRedirect();

        $payout=$user->payouts()->firstOrFail();

        $this->actingAs($user)->post(route('wallet.payout.cancel',$payout))
            ->assertRedirect();

        $this->assertSame('cancelled',$payout->fresh()->status);
        $this->assertSame(25.0,$wallet->fresh()->balance('available'));
        $this->assertSame(0.0,$wallet->fresh()->balance('payout_pending'));
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
        ]);
        $user->forceFill(['email_verified_at'=>now()])->save();
        $user->profile()->create([
            'bank_iban'=>'DE89370400440532013000',
            'bank_account_holder'=>'Anna Beispiel',
            'payout_details_changed_at'=>now()->subHours(25),
            'payout_name_approved_at'=>now()->subHours(25),
        ]);

        return $user;
    }
}
