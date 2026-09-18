<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\ReliabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MasterPromptCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_offer_keeps_existing_order_snapshot(): void
    {
        [$provider,$category]=$this->providerAndCategory('snapshot@example.test');
        $offer=$this->offer($category,'Snapshot-Angebot');

        $order=Order::create([
            'order_number'=>'20260000101',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'requested',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>'Snapshot-Angebot',
                'base_compensation'=>40,
                'duration_days'=>2,
            ],
        ]);

        $offer->delete();

        $order->refresh();
        $this->assertNull($order->offer_id);
        $this->assertSame('Snapshot-Angebot',$order->offer_snapshot['title']);
        $this->assertDatabaseHas('orders',['id'=>$order->id]);
    }

    public function test_only_execution_phases_count_against_personal_order_limit(): void
    {
        [$provider,$category]=$this->providerAndCategory('limit@example.test');
        $offer=$this->offer($category,'Limit-Angebot');

        $this->makeOrder($provider,$offer,'requested','20260000111');
        $this->makeOrder($provider,$offer,'approved','20260000112');
        $this->makeOrder($provider,$offer,'waiting_shipping','20260000113',[
            'execution_completed_at'=>now(),
        ]);
        $this->makeOrder($provider,$offer,'paused','20260000114');
        $this->makeOrder($provider,$offer,'paused','20260000115',[
            'execution_completed_at'=>now(),
        ]);

        $count=Order::where('user_id',$provider->id)->countsAgainstPersonalLimit()->count();

        $this->assertSame(2,$count);
        $this->assertSame(5,$provider->effectiveOrderLimit());
    }

    public function test_reliability_restriction_needs_five_clean_orders_and_manual_admin_lift(): void
    {
        Mail::fake();

        [$provider,$category]=$this->providerAndCategory('probation@example.test');
        $offer=$this->offer($category,'Bewährungsangebot');
        $service=app(ReliabilityService::class);

        $service->recordViolation(
            $provider,
            null,
            'proof_window_missed',
            'Verpflichtetes Nachweisfenster versäumt'
        );

        $restriction=$provider->restrictions()->where('type','reliability')->where('active',true)->firstOrFail();
        $this->assertSame(4,(int)$restriction->max_active_orders);
        $this->assertSame(0,(int)$restriction->successful_count);
        $this->assertSame(4,$provider->fresh()->effectiveOrderLimit());

        for($i=1;$i<=5;$i++){
            $order=$this->makeOrder(
                $provider,
                $offer,
                'completed',
                '2026000020'.$i,
                ['completed_at'=>now(),'reliability_issue_count'=>0]
            );
            $service->recordCleanCompletion($order);
        }

        $restriction->refresh();
        $this->assertSame(5,(int)$restriction->successful_count);
        $this->assertTrue((bool)$restriction->active);

        $admin=User::create([
            'role'=>'admin',
            'first_name'=>'Admin',
            'last_name'=>'Konto',
            'birth_date'=>'1970-01-01',
            'email'=>'admin-core@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);

        $service->liftRestriction($restriction,$admin);

        $this->assertFalse((bool)$restriction->fresh()->active);
        $this->assertSame(5,$provider->fresh()->effectiveOrderLimit());
        $this->assertSame(0,(int)$provider->profile()->firstOrFail()->reliability_cycle_violations);
    }

    public function test_reopening_rejected_payout_reserves_money_again_without_double_restoration(): void
    {
        Mail::fake();

        [$provider]=$this->providerAndCategory('payout-cycle@example.test');
        $admin=User::create([
            'role'=>'admin',
            'first_name'=>'Admin',
            'last_name'=>'Konto',
            'birth_date'=>'1970-01-01',
            'email'=>'admin-payout@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $admin->forceFill(['email_verified_at'=>now()])->save();

        $wallet=WalletAccount::where('user_id',$provider->id)->firstOrFail();
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'test_credit',
            'amount'=>50,
            'description'=>'Testguthaben',
        ]);
        $wallet->entries()->create([
            'bucket'=>'available',
            'entry_type'=>'payout_reserved',
            'amount'=>-20,
            'reference'=>'P-CYCLE',
            'description'=>'Reserviert',
        ]);
        $wallet->entries()->create([
            'bucket'=>'payout_pending',
            'entry_type'=>'payout_requested',
            'amount'=>20,
            'reference'=>'P-CYCLE',
            'description'=>'Beantragt',
        ]);

        $payout=PayoutRequest::create([
            'payout_number'=>'P-CYCLE',
            'user_id'=>$provider->id,
            'amount'=>20,
            'status'=>'requested',
            'method'=>'bank_transfer',
            'destination'=>[
                'iban'=>'DE89370400440532013000',
                'account_holder'=>'Anna Beispiel',
            ],
            'processing_date'=>now('Europe/Berlin')->next('Friday')->toDateString(),
        ]);

        $this->actingAs($admin)->post(route('admin.payouts.update',$payout),[
            'status'=>'rejected',
            'rejection_reason'=>'Testablehnung',
        ])->assertRedirect();

        $this->assertSame(50.0,$wallet->fresh()->balance('available'));
        $this->assertSame(0.0,$wallet->fresh()->balance('payout_pending'));

        $this->actingAs($admin)->post(route('admin.payouts.update',$payout->fresh()),[
            'status'=>'requested',
        ])->assertRedirect();

        $this->assertSame(30.0,$wallet->fresh()->balance('available'));
        $this->assertSame(20.0,$wallet->fresh()->balance('payout_pending'));

        $this->actingAs($admin)->post(route('admin.payouts.update',$payout->fresh()),[
            'status'=>'rejected',
            'rejection_reason'=>'Nochmals abgelehnt',
        ])->assertRedirect();

        $this->assertSame(50.0,$wallet->fresh()->balance('available'));
        $this->assertSame(0.0,$wallet->fresh()->balance('payout_pending'));
    }

    public function test_offer_duplicate_remaps_internal_option_dependencies(): void
    {
        [$provider,$category]=$this->providerAndCategory('duplicate-provider@example.test');

        $admin=User::create([
            'role'=>'admin',
            'username'=>'duplicate.admin',
            'first_name'=>'Admin',
            'last_name'=>'Duplicate',
            'birth_date'=>'1970-01-01',
            'email'=>'duplicate-admin@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $admin->forceFill(['email_verified_at'=>now()])->save();

        $offer=$this->offer($category,'Dependency Source');

        $base=$offer->options()->create([
            'name'=>'Basis-Extra',
            'price_delta'=>5,
            'extra_proofs_per_day'=>0,
            'extra_duration_days'=>0,
            'required'=>false,
            'active'=>true,
            'sort_order'=>0,
            'rules'=>[],
        ]);

        $dependent=$offer->options()->create([
            'name'=>'Abhängiges Extra',
            'price_delta'=>10,
            'extra_proofs_per_day'=>0,
            'extra_duration_days'=>0,
            'required'=>false,
            'active'=>true,
            'sort_order'=>1,
            'rules'=>[
                'requires_ids'=>[$base->id],
                'excludes_ids'=>[],
                'min_duration_days'=>0,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.offers.duplicate',$offer))
            ->assertRedirect();

        $copy=Offer::where('id','!=',$offer->id)
            ->where('title','Dependency Source – Kopie')
            ->firstOrFail();

        $copiedBase=$copy->options()->where('name','Basis-Extra')->firstOrFail();
        $copiedDependent=$copy->options()->where('name','Abhängiges Extra')->firstOrFail();

        $this->assertNotSame($base->id,$copiedBase->id);
        $this->assertNotSame($dependent->id,$copiedDependent->id);
        $this->assertSame([$copiedBase->id],array_map('intval',$copiedDependent->rules['requires_ids']??[]));
        $this->assertNotContains($base->id,array_map('intval',$copiedDependent->rules['requires_ids']??[]));
    }


    public function test_wallet_override_counts_later_ledger_entries_even_in_same_second(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 20:30:00','Europe/Berlin'));

        try{
            [$provider]=$this->providerAndCategory('wallet-override@example.test');

            $admin=User::create([
                'role'=>'admin',
                'username'=>'wallet.override.admin',
                'first_name'=>'Admin',
                'last_name'=>'Wallet',
                'birth_date'=>'1970-01-01',
                'email'=>'wallet-override-admin@example.test',
                'password'=>Hash::make('VerySecurePassword123!'),
                'status'=>'active',
            ]);
            $admin->forceFill(['email_verified_at'=>now()])->save();

            $wallet=$provider->walletAccount()->firstOrFail();
            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'old_credit',
                'amount'=>40,
                'description'=>'Altguthaben',
            ]);

            $this->actingAs($admin)->post(route('admin.users.wallet-override',$provider),[
                'available_balance'=>100,
                'reason'=>'Korrektur des verfügbaren Guthabens',
            ])->assertRedirect();

            $wallet->refresh();
            $this->assertEquals(100.0,$wallet->balance('available'));

            $wallet->entries()->create([
                'bucket'=>'available',
                'entry_type'=>'new_credit',
                'amount'=>25,
                'description'=>'Gutschrift direkt nach Override',
            ]);

            $this->assertEquals(125.0,$wallet->fresh()->balance('available'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    private function providerAndCategory(string $email): array
    {
        $provider=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>$email,
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $provider->forceFill(['email_verified_at'=>now()])->save();
        $provider->profile()->create();
        WalletAccount::create(['user_id'=>$provider->id]);

        $category=Category::firstOrCreate(
            ['slug'=>'core-tests'],
            ['name'=>'Core Tests','active'=>true]
        );

        return [$provider,$category];
    }

    private function offer(Category $category, string $title): Offer
    {
        return Offer::create([
            'category_id'=>$category->id,
            'title'=>$title,
            'slug'=>strtolower(str_replace(' ','-',$title)).'-'.uniqid(),
            'base_compensation'=>40,
            'duration_days'=>2,
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
        ]);
    }

    private function makeOrder(User $provider, Offer $offer, string $status, string $number, array $extra=[]): Order
    {
        return Order::create(array_merge([
            'order_number'=>$number,
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>$status,
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'base_compensation'=>40,
                'duration_days'=>2,
                'is_sock_wearing'=>false,
            ],
        ],$extra));
    }
}
