<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivacyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_cannot_be_completed_while_wallet_contains_money(): void
    {
        [$provider,$admin]=$this->makeUsers();

        $wallet=WalletAccount::create(['user_id'=>$provider->id]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>10,
            'description'=>'Test',
        ]);

        $privacyRequest=PrivacyRequest::create([
            'user_id'=>$provider->id,
            'type'=>'deletion',
            'status'=>'approved',
        ]);

        $response=$this->actingAs($admin)->post(
            route('admin.privacy.anonymize',$privacyRequest),
            ['confirm'=>'1']
        );

        $response->assertStatus(422);
        $this->assertSame('active',$provider->fresh()->status);
    }

    public function test_approved_request_can_complete_for_clean_account(): void
    {
        [$provider,$admin]=$this->makeUsers();

        $provider->profile()->create([
            'phone'=>'0123456789',
            'street'=>'Teststraße 1',
            'postal_code'=>'12345',
            'city'=>'Teststadt',
            'country_code'=>'DE',
        ]);

        $privacyRequest=PrivacyRequest::create([
            'user_id'=>$provider->id,
            'type'=>'deletion',
            'status'=>'approved',
        ]);

        $response=$this->actingAs($admin)->post(
            route('admin.privacy.anonymize',$privacyRequest),
            ['confirm'=>'1']
        );

        $response->assertRedirect(route('admin.privacy.index'));

        $provider->refresh();
        $this->assertSame('deleted',$provider->status);
        $this->assertSame('Gelöscht',$provider->first_name);
        $this->assertStringContainsString('@invalid.local',$provider->email);
        $this->assertNull($provider->profile->fresh()->phone);
        $this->assertSame('completed',$privacyRequest->fresh()->status);
    }

    private function makeUsers(): array
    {
        $provider=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>'privacy@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);

        $admin=User::create([
            'role'=>'admin',
            'first_name'=>'Super',
            'last_name'=>'Admin',
            'birth_date'=>'1970-01-01',
            'email'=>'admin@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);

        return [$provider,$admin];
    }
}
