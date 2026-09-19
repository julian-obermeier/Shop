<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_offer_detail_discloses_inspection_compensation_and_option_rules_before_request(): void
    {
        [$user,$offer]=$this->makeUserAndOffer(true);

        $offer->update([
            'inspection_config'=>[
                'categories'=>[
                    'appearance'=>['label'=>'Aussehen','ko'=>true],
                    'smell'=>['label'=>'Geruch','ko'=>false],
                    'taste'=>['label'=>'Geschmack','ko'=>false],
                    'proofs'=>['label'=>'Nachweise','ko'=>false],
                    'extras'=>['label'=>'Extras','ko'=>false],
                ],
                'points_affect_compensation'=>true,
                'score_bands'=>[
                    ['min'=>45,'max'=>50,'percentage'=>100],
                    ['min'=>35,'max'=>44,'percentage'=>80],
                ],
                'start_face_required'=>true,
            ],
        ]);

        $base=$offer->options()->create([
            'name'=>'Sport',
            'description'=>'Beim Sport tragen',
            'price_delta'=>5,
            'extra_proofs_per_day'=>0,
            'extra_duration_days'=>0,
            'required'=>false,
            'active'=>true,
            'sort_order'=>0,
            'rules'=>[],
        ]);

        $offer->options()->create([
            'name'=>'Schlafen',
            'description'=>'Beim Schlafen tragen',
            'price_delta'=>10,
            'extra_proofs_per_day'=>1,
            'extra_duration_days'=>1,
            'required'=>false,
            'active'=>true,
            'sort_order'=>1,
            'rules'=>[
                'requires_ids'=>[$base->id],
                'excludes_ids'=>[],
                'min_duration_days'=>3,
            ],
        ]);

        $response=$this->actingAs($user)->get(route('offers.show',$offer));

        $response->assertOk();
        $response->assertSee('Aussehen');
        $response->assertSee('KO-Kriterium');
        $response->assertSee('45–50 Punkte');
        $response->assertSee('100,00 %');
        $response->assertSee('35–44 Punkte');
        $response->assertSee('80,00 %');
        $response->assertSee('Gesicht muss sichtbar sein');
        $response->assertSee('Benötigt:');
        $response->assertSee('Sport');
        $response->assertSee('Mindestdauer:');
        $response->assertSee('3 Tage');
        $response->assertSee('Zusatzdauer:');
        $response->assertSee('+1 Tag(e)');
        $response->assertSee('Zusatznachweise:');
        $response->assertSee('+1 pro Tag');
    }


    public function test_unconfirmed_request_can_be_withdrawn_without_reliability_penalty(): void
    {
        Mail::fake();

        [$user,$offer]=$this->makeUserAndOffer(true);

        $this->actingAs($user)->post(route('offers.accept',$offer),[
            'proposed_start_date'=>now('Europe/Berlin')->addDays(2)->toDateString(),
            'confirm_summary'=>'1',
        ])->assertRedirect();

        $order=$user->orders()->firstOrFail();

        $this->actingAs($user)
            ->post(route('orders.withdraw',$order))
            ->assertRedirect(route('orders.index'));

        $order->refresh();

        $this->assertSame('cancelled',$order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(0,(int)$order->reliability_issue_count);
        $this->assertDatabaseHas('order_status_history',[
            'order_id'=>$order->id,
            'from_status'=>'requested',
            'to_status'=>'cancelled',
        ]);
        $this->assertDatabaseMissing('reliability_events',[
            'order_id'=>$order->id,
        ]);
    }

    public function test_admin_date_counterproposal_requires_provider_acceptance_before_order_is_confirmed(): void
    {
        Mail::fake();

        [$user,$offer]=$this->makeUserAndOffer(true);
        $admin=$this->makeAdmin('date-admin@example.test','date.admin');

        $this->actingAs($user)->post(route('offers.accept',$offer),[
            'proposed_start_date'=>now('Europe/Berlin')->addDays(2)->toDateString(),
            'confirm_summary'=>'1',
        ])->assertRedirect();

        $order=$user->orders()->firstOrFail();
        $alternate=now('Europe/Berlin')->addDays(5)->toDateString();

        $this->actingAs($admin)->post(route('admin.orders.propose-date',$order),[
            'start_date'=>$alternate,
        ])->assertRedirect();

        $order->refresh();

        $this->assertSame('awaiting_date_confirmation',$order->status);
        $this->assertSame($alternate,$order->proposed_start_date?->toDateString());
        $this->assertNull($order->confirmed_start_date);

        $this->actingAs($user)
            ->post(route('orders.accept-date',$order))
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('approved',$order->status);
        $this->assertSame($alternate,$order->confirmed_start_date?->toDateString());
        $this->assertSame($alternate,$order->proposed_start_date?->toDateString());
    }

    public function test_generic_admin_abort_rejects_unconfirmed_request_but_can_cancel_confirmed_prestart_order(): void
    {
        Mail::fake();

        [$user,$offer]=$this->makeUserAndOffer(true);
        $admin=$this->makeAdmin('cancel-admin@example.test','cancel.admin');

        $this->actingAs($user)->post(route('offers.accept',$offer),[
            'proposed_start_date'=>now('Europe/Berlin')->addDays(3)->toDateString(),
            'confirm_summary'=>'1',
        ])->assertRedirect();

        $order=$user->orders()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.orders.status',$order),[
            'status'=>'cancelled',
            'reason'=>'Vor Bestätigung nicht über generischen Abbruch bearbeiten',
            'compensation_amount'=>0,
        ])->assertStatus(422);

        $this->assertSame('requested',$order->fresh()->status);

        $this->actingAs($admin)->post(route('admin.orders.approve',$order->fresh()),[
            'start_date'=>now('Europe/Berlin')->addDays(3)->toDateString(),
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('approved',$order->status);

        $this->actingAs($admin)->post(route('admin.orders.status',$order),[
            'status'=>'cancelled',
            'reason'=>'Organisatorischer Abbruch vor dem Aktivierungstag',
            'compensation_amount'=>0,
        ])->assertRedirect();

        $order->refresh();

        $this->assertSame('cancelled',$order->status);
        $this->assertEquals(0.0,(float)$order->final_compensation);
        $this->assertNotNull($order->completed_at);
        $this->assertDatabaseHas('order_status_history',[
            'order_id'=>$order->id,
            'from_status'=>'approved',
            'to_status'=>'cancelled',
            'reason'=>'Organisatorischer Abbruch vor dem Aktivierungstag',
        ]);
    }


    private function makeAdmin(string $email, string $username): User
    {
        $admin=User::create([
            'role'=>'admin',
            'username'=>$username,
            'first_name'=>'Admin',
            'last_name'=>'Konto',
            'birth_date'=>'1970-01-01',
            'email'=>$email,
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $admin->forceFill(['email_verified_at'=>now()])->save();

        return $admin;
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
