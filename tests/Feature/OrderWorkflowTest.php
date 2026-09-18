<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_request_requires_verified_email(): void
    {
        [$user,$offer]=$this->makeUserAndOffer(false);

        $response=$this->actingAs($user)->post(route('offers.accept',$offer),[
            'proposed_start_date'=>now('Europe/Berlin')->addDays(2)->toDateString(),
            'confirm_summary'=>'1',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_required_dynamic_field_is_enforced_and_snapshotted_without_wallet_reservation(): void
    {
        [$user,$offer]=$this->makeUserAndOffer(true);

        $field=$offer->fields()->create([
            'label'=>'Schuhgröße',
            'key'=>'schuhgroesse',
            'type'=>'number',
            'required'=>true,
            'sort_order'=>0,
            'active'=>true,
        ]);

        $payload=[
            'proposed_start_date'=>now('Europe/Berlin')->addDays(2)->toDateString(),
            'confirm_summary'=>'1',
        ];

        $missing=$this->actingAs($user)->post(route('offers.accept',$offer),$payload);
        $missing->assertStatus(422);

        $success=$this->actingAs($user)->post(route('offers.accept',$offer),$payload+[
            'fields'=>['schuhgroesse'=>'39'],
        ]);

        $order=$user->orders()->firstOrFail();
        $success->assertRedirect(route('orders.show',$order));

        $this->assertSame('requested',$order->status);
        $this->assertSame($payload['proposed_start_date'],$order->proposed_start_date?->toDateString());

        $this->assertDatabaseHas('order_field_values',[
            'order_id'=>$order->id,
            'offer_field_id'=>$field->id,
            'key'=>'schuhgroesse',
            'value'=>'39',
        ]);

        $this->assertDatabaseMissing('wallet_ledger_entries',[
            'order_id'=>$order->id,
        ]);
    }

    private function makeUserAndOffer(bool $emailVerified): array
    {
        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>($emailVerified?'verified':'unverified').'@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);

        if($emailVerified){
            $user->forceFill(['email_verified_at'=>now()])->save();
        }

        $user->profile()->create();
        WalletAccount::create(['user_id'=>$user->id]);

        $category=Category::create([
            'name'=>'Socken',
            'slug'=>'socken-test',
            'active'=>true,
        ]);

        $offer=Offer::create([
            'category_id'=>$category->id,
            'title'=>'Testangebot',
            'slug'=>'testangebot-'.$user->id,
            'base_compensation'=>40,
            'duration_days'=>2,
            'minimum_minutes_per_day'=>0,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'tracking_mode'=>'optional',
            'requires_precheck'=>false,
            'is_sock_wearing'=>false,
            'proof_requirements'=>[[
                'key'=>'daily',
                'label'=>'Tagesnachweis',
                'start'=>'00:00',
                'end'=>'23:59',
                'required_images'=>1,
                'text_required'=>false,
                'face_required'=>false,
            ]],
            'active'=>true,
        ]);

        return [$user,$offer];
    }
}
