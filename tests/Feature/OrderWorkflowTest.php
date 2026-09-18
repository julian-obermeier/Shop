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

    public function test_offer_acceptance_requires_verified_email(): void
    {
        [$user,$offer]=$this->makeUserAndOffer(false);

        $response=$this->actingAs($user)->post(route('offers.accept',$offer),[]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_required_dynamic_field_is_enforced_and_snapshotted(): void
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

        $missing=$this->actingAs($user)->post(route('offers.accept',$offer),[]);
        $missing->assertStatus(422);

        $success=$this->actingAs($user)->post(route('offers.accept',$offer),[
            'fields'=>['schuhgroesse'=>'39'],
        ]);

        $order=$user->orders()->firstOrFail();
        $success->assertRedirect(route('orders.show',$order));

        $this->assertDatabaseHas('order_field_values',[
            'order_id'=>$order->id,
            'offer_field_id'=>$field->id,
            'key'=>'schuhgroesse',
            'value'=>'39',
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries',[
            'order_id'=>$order->id,
            'bucket'=>'pending',
            'entry_type'=>'order_reserved',
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
            'email_verified_at'=>$emailVerified?now():null,
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);
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
            'minimum_minutes_per_day'=>60,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'requires_precheck'=>false,
            'active'=>true,
        ]);

        return [$user,$offer];
    }
}
