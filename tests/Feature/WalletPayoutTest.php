<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletAccount;
use Carbon\CarbonImmutable;
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

    public function test_payout_processing_date_respects_thursday_cutoff_and_friday_rollover(): void
    {
        Mail::fake();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 23:59:30','Europe/Berlin'));

        try{
            $thursdayUser=$this->makeVerifiedUser('cutoff-thursday@example.test');
            $thursdayWallet=WalletAccount::create(['user_id'=>$thursdayUser->id]);
            $thursdayWallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'test_credit',
                'amount'=>10,
                'description'=>'Testguthaben',
            ]);

            $this->actingAs($thursdayUser)->post(route('wallet.payout'),[
                'amount'=>5,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $this->assertSame(
                '2026-09-18',
                $thursdayUser->payouts()->firstOrFail()->processing_date?->toDateString()
            );

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 00:00:00','Europe/Berlin'));

            $fridayUser=$this->makeVerifiedUser('cutoff-friday@example.test');
            $fridayWallet=WalletAccount::create(['user_id'=>$fridayUser->id]);
            $fridayWallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'test_credit',
                'amount'=>10,
                'description'=>'Testguthaben',
            ]);

            $this->actingAs($fridayUser)->post(route('wallet.payout'),[
                'amount'=>5,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $this->assertSame(
                '2026-09-25',
                $fridayUser->payouts()->firstOrFail()->processing_date?->toDateString()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_admin_cannot_financially_process_regular_payout_before_processing_friday(): void
    {
        Mail::fake();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00','Europe/Berlin'));

        try{
            $user=$this->makeVerifiedUser('early-process@example.test');
            $wallet=WalletAccount::create(['user_id'=>$user->id]);
            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'test_credit',
                'amount'=>20,
                'description'=>'Testguthaben',
            ]);

            $this->actingAs($user)->post(route('wallet.payout'),[
                'amount'=>10,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $payout=$user->payouts()->firstOrFail();
            $this->assertSame('2026-09-18',$payout->processing_date?->toDateString());

            $admin=User::create([
                'role'=>'admin',
                'username'=>'payout.admin',
                'first_name'=>'Admin',
                'last_name'=>'Payout',
                'birth_date'=>'1970-01-01',
                'email'=>'payout-admin@example.test',
                'password'=>Hash::make('VerySecurePassword123!'),
                'status'=>'active',
            ]);
            $admin->forceFill(['email_verified_at'=>now()])->save();

            $this->actingAs($admin)->post(route('admin.payouts.update',$payout),[
                'status'=>'approved',
            ])->assertStatus(422);

            $this->assertSame('requested',$payout->fresh()->status);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 09:00:00','Europe/Berlin'));

            $this->actingAs($admin)->post(route('admin.payouts.update',$payout->fresh()),[
                'status'=>'approved',
            ])->assertRedirect();

            $this->assertSame('approved',$payout->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    public function test_cancelled_payout_can_be_recreated_in_same_second_with_unique_number(): void
    {
        Mail::fake();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 18:00:00','Europe/Berlin'));

        try{
            $user=$this->makeVerifiedUser('same-second@example.test');
            $wallet=WalletAccount::create(['user_id'=>$user->id]);
            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'test_credit',
                'amount'=>30,
                'description'=>'Testguthaben',
            ]);

            $this->actingAs($user)->post(route('wallet.payout'),[
                'amount'=>10,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $first=$user->payouts()->firstOrFail();

            $this->actingAs($user)->post(route('wallet.payout.cancel',$first))
                ->assertRedirect();

            $this->actingAs($user)->post(route('wallet.payout'),[
                'amount'=>10,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $second=$user->payouts()->latest('id')->firstOrFail();

            $this->assertNotSame($first->payout_number,$second->payout_number);
            $this->assertSame('cancelled',$first->fresh()->status);
            $this->assertSame('requested',$second->status);
            $this->assertEquals(20.0,$wallet->fresh()->balance('available'));
            $this->assertEquals(10.0,$wallet->fresh()->balance('payout_pending'));
        } finally {
            CarbonImmutable::setTestNow();
        }
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
