<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MasterPromptRemainingCriticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_request_uses_three_calendar_days_and_operator_quote_expires_after_24_hours(): void
    {
        Mail::fake();

        $provider=$this->provider('return-deadline@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Return Deadline');

        $reviewed=CarbonImmutable::parse('2026-09-19 10:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-22 23:59:30','Europe/Berlin'));

        try{
            $order=$this->rejectedOrder($provider,$offer,'20260000501',$reviewed);

            $this->actingAs($provider)->post(route('orders.return-request',$order),[
                'method'=>'operator_quote',
            ])->assertRedirect();

            $return=$order->fresh()->returnRequest()->firstOrFail();
            $this->assertSame('awaiting_quote_payment',$return->status);
            $this->assertEquals(
                86400,
                CarbonImmutable::now('Europe/Berlin')->diffInSeconds($return->fulfillment_due_at,false)
            );

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 00:00:01','Europe/Berlin'));

            $lateOrder=$this->rejectedOrder($provider,$offer,'20260000502',$reviewed);
            $this->actingAs($provider)->post(route('orders.return-request',$lateOrder),[
                'method'=>'operator_quote',
            ])->assertStatus(422);
            $this->assertNull($lateOrder->fresh()->returnRequest);

            CarbonImmutable::setTestNow($return->fulfillment_due_at->copy()->addSecond());
            $this->artisan('orders:deadlines')->assertExitCode(0);

            $this->assertSame('expired',$return->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_waitlist_reservation_expires_after_24_hours_and_next_fifo_entry_gets_slot(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-19 09:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $category=$this->category();
            $offer=$this->offer($category,'Waitlist Deadline');
            $offer->update(['capacity'=>1]);

            $first=$this->provider('waitlist-first@example.test');
            $second=$this->provider('waitlist-second@example.test');

            $entry1=OfferWaitlistEntry::create([
                'offer_id'=>$offer->id,
                'user_id'=>$first->id,
                'status'=>'waiting',
            ]);

            CarbonImmutable::setTestNow($now->addMinute());

            $entry2=OfferWaitlistEntry::create([
                'offer_id'=>$offer->id,
                'user_id'=>$second->id,
                'status'=>'waiting',
            ]);

            CarbonImmutable::setTestNow($now->addMinutes(2));

            $service=app(WaitlistService::class);
            $reserved=$service->allocateNext($offer,app(NotificationService::class));

            $this->assertSame($entry1->id,$reserved?->id);
            $this->assertSame('reserved',$entry1->fresh()->status);
            $this->assertEquals(
                86400,
                CarbonImmutable::now('Europe/Berlin')->diffInSeconds($entry1->fresh()->reservation_expires_at,false)
            );

            CarbonImmutable::setTestNow($entry1->fresh()->reservation_expires_at->copy()->addSecond());

            $expired=$service->expireReservations(app(NotificationService::class));

            $this->assertSame(1,$expired);
            $this->assertSame('removed',$entry1->fresh()->status);
            $this->assertSame('reserved',$entry2->fresh()->status);
            $this->assertNotNull($entry2->fresh()->reservation_expires_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ko_failure_forces_zero_compensation_even_when_extra_is_fulfilled(): void
    {
        Mail::fake();

        $provider=$this->provider('ko-goods@example.test');
        $admin=$this->admin('ko-admin@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'KO Goods');

        $order=$this->inspectionReadyOrder(
            $provider,
            $admin,
            $offer,
            '20260000503',
            [
                'categories'=>[
                    'appearance'=>['label'=>'Aussehen','ko'=>true],
                    'smell'=>['label'=>'Geruch','ko'=>false],
                    'taste'=>['label'=>'Geschmack','ko'=>false],
                    'proofs'=>['label'=>'Nachweise','ko'=>false],
                    'extras'=>['label'=>'Extras','ko'=>false],
                ],
                'points_affect_compensation'=>false,
                'score_bands'=>[],
            ],
            50
        );

        $option=$order->options()->create([
            'offer_option_id'=>null,
            'name'=>'Sport',
            'price_delta'=>10,
            'snapshot'=>['name'=>'Sport','price_delta'=>10],
        ]);

        $categories=$this->inspectionCategories();
        $categories['appearance']['passed']='0';

        $this->actingAs($admin)->post(route('admin.orders.goods-inspection',$order),[
            'result'=>'accepted',
            'categories'=>$categories,
            'manual_base_percentage'=>100,
            'extras'=>[
                $option->id=>['fulfilled'=>'1','comment'=>'Erfüllt'],
            ],
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('completed',$order->status);
        $this->assertEquals(0.0,(float)$order->final_compensation);
        $this->assertEquals(0.0,$provider->walletAccount()->firstOrFail()->balance('available'));
        $this->assertTrue((bool)($order->goodsInspection->categories['_ko_failed']??false));
    }

    public function test_point_band_reduces_base_only_and_fulfilled_extra_remains_full(): void
    {
        Mail::fake();

        $provider=$this->provider('points-goods@example.test');
        $admin=$this->admin('points-admin@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Points Goods');

        $order=$this->inspectionReadyOrder(
            $provider,
            $admin,
            $offer,
            '20260000504',
            [
                'categories'=>[
                    'appearance'=>['label'=>'Aussehen','ko'=>false],
                    'smell'=>['label'=>'Geruch','ko'=>false],
                    'taste'=>['label'=>'Geschmack','ko'=>false],
                    'proofs'=>['label'=>'Nachweise','ko'=>false],
                    'extras'=>['label'=>'Extras','ko'=>false],
                ],
                'points_affect_compensation'=>true,
                'score_bands'=>[
                    ['min'=>40,'max'=>50,'percentage'=>80],
                    ['min'=>0,'max'=>39,'percentage'=>50],
                ],
            ],
            50
        );

        $option=$order->options()->create([
            'offer_option_id'=>null,
            'name'=>'Schlafen',
            'price_delta'=>10,
            'snapshot'=>['name'=>'Schlafen','price_delta'=>10],
        ]);

        $categories=$this->inspectionCategories();
        $categories['extras']['points']=5;

        $this->actingAs($admin)->post(route('admin.orders.goods-inspection',$order),[
            'result'=>'accepted',
            'categories'=>$categories,
            'extras'=>[
                $option->id=>['fulfilled'=>'1','comment'=>'Erfüllt'],
            ],
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('completed',$order->status);
        $this->assertEquals(42.0,(float)$order->final_compensation);
        $this->assertEquals(42.0,$provider->walletAccount()->firstOrFail()->balance('available'));
        $this->assertEquals(80.0,(float)$order->goodsInspection->base_percentage);
    }

    public function test_open_payout_keeps_immutable_destination_snapshot_after_profile_change(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-17 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('snapshot-payout@example.test');
            $profile=$provider->profile()->firstOrFail();
            $profile->update([
                'bank_iban'=>'DE11111111111111111111',
                'bank_account_holder'=>'Anna Beispiel',
                'payout_details_changed_at'=>$now->subHours(25),
                'payout_name_approved_at'=>$now->subHours(25),
            ]);

            $wallet=$provider->walletAccount()->firstOrFail();
            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'test_credit',
                'amount'=>100,
                'description'=>'Testguthaben',
            ]);

            $this->actingAs($provider)->post(route('wallet.payout'),[
                'amount'=>25,
                'method'=>'bank_transfer',
            ])->assertRedirect();

            $payout=$provider->payouts()->firstOrFail();
            $this->assertSame('DE11111111111111111111',$payout->destination['iban']);

            $profile->update([
                'bank_iban'=>'DE22222222222222222222',
                'bank_account_holder'=>'Anderer Empfänger',
                'payout_details_changed_at'=>$now,
                'payout_name_approved_at'=>null,
            ]);

            $payout->refresh();
            $this->assertSame('DE11111111111111111111',$payout->destination['iban']);
            $this->assertSame('Anna Beispiel',$payout->destination['account_holder']);
            $this->assertSame('requested',$payout->status);
            $this->assertEquals(75.0,$wallet->fresh()->balance('available'));
            $this->assertEquals(25.0,$wallet->fresh()->balance('payout_pending'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function provider(string $email): User
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
        $user->profile()->create();
        WalletAccount::create(['user_id'=>$user->id]);

        return $user;
    }

    private function admin(string $email): User
    {
        $user=User::create([
            'role'=>'admin',
            'username'=>'critical.'.uniqid(),
            'first_name'=>'Admin',
            'last_name'=>'Critical',
            'birth_date'=>'1970-01-01',
            'email'=>$email,
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $user->forceFill(['email_verified_at'=>now()])->save();

        return $user;
    }

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug'=>'remaining-critical'],
            ['name'=>'Remaining Critical','active'=>true]
        );
    }

    private function offer(Category $category, string $title): Offer
    {
        return Offer::create([
            'category_id'=>$category->id,
            'title'=>$title,
            'slug'=>strtolower(str_replace(' ','-',$title)).'-'.uniqid(),
            'base_compensation'=>40,
            'duration_days'=>1,
            'minimum_minutes_per_day'=>0,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'tracking_mode'=>'optional',
            'requires_precheck'=>false,
            'is_sock_wearing'=>false,
            'active'=>true,
            'proof_requirements'=>[[
                'key'=>'daily',
                'label'=>'Tagesnachweis',
                'start'=>'00:00',
                'end'=>'23:59',
                'required_images'=>1,
            ]],
            'inspection_config'=>[
                'categories'=>[],
                'points_affect_compensation'=>false,
                'score_bands'=>[],
                'start_face_required'=>false,
            ],
        ]);
    }

    private function rejectedOrder(User $provider, Offer $offer, string $number, CarbonImmutable $reviewed): Order
    {
        $order=Order::create([
            'order_number'=>$number,
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'rejected',
            'compensation_total'=>40,
            'final_compensation'=>0,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
            'completed_at'=>$reviewed,
        ]);

        $order->goodsInspection()->create([
            'reviewed_by'=>null,
            'categories'=>[],
            'base_percentage'=>0,
            'extra_results'=>[],
            'calculated_compensation'=>0,
            'result'=>'rejected',
            'reason'=>'Abgelehnt',
            'reviewed_at'=>$reviewed,
        ]);

        return $order;
    }

    private function inspectionReadyOrder(
        User $provider,
        User $admin,
        Offer $offer,
        string $number,
        array $inspectionConfig,
        float $compensationTotal
    ): Order {
        $snapshot=[
            'title'=>$offer->title,
            'base_compensation'=>40,
            'duration_days'=>1,
            'tracking_mode'=>'optional',
            'inspection_config'=>$inspectionConfig,
        ];

        $order=Order::create([
            'order_number'=>$number,
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'inspection',
            'compensation_total'=>$compensationTotal,
            'offer_snapshot'=>$snapshot,
            'current_requirements'=>$snapshot,
            'series_number'=>1,
            'execution_completed_at'=>now()->subDay(),
            'received_at'=>now(),
        ]);

        OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>0,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->subDays(2)->toDateString(),
            'required_proofs'=>1,
            'status'=>'accepted',
            'counts_toward_series'=>true,
        ]);

        OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->subDay()->toDateString(),
            'required_proofs'=>1,
            'status'=>'accepted',
            'counts_toward_series'=>true,
        ]);

        $order->shipment()->create([
            'carrier'=>'DHL',
            'status'=>'delivered',
            'review_status'=>'accepted',
            'shipped_at'=>now()->subDay(),
            'delivered_at'=>now(),
            'ownership_transferred_at'=>now()->subDay(),
            'risk_transferred_at'=>now(),
        ]);

        $order->goodsReceipt()->create([
            'received_by'=>$admin->id,
            'status'=>'received',
            'complete'=>true,
            'received_at'=>now(),
        ]);

        return $order;
    }

    private function inspectionCategories(): array
    {
        return [
            'appearance'=>['passed'=>'1','points'=>10,'comment'=>null],
            'smell'=>['passed'=>'1','points'=>10,'comment'=>null],
            'taste'=>['passed'=>'1','points'=>10,'comment'=>null],
            'proofs'=>['passed'=>'1','points'=>10,'comment'=>null],
            'extras'=>['passed'=>'1','points'=>10,'comment'=>null],
        ];
    }
}
