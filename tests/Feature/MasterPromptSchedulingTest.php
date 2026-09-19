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
use App\Services\OrderService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MasterPromptSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sock_extension_shifts_all_following_confirmed_sock_orders_cascadingly(): void
    {
        Mail::fake();

        $provider=$this->provider('sock-cascade@example.test');
        $admin=$this->admin('sock-admin@example.test');
        $category=$this->category();

        $offerA=$this->sockOffer($category,'Sock A',3);
        $offerB=$this->sockOffer($category,'Sock B',2);
        $offerC=$this->sockOffer($category,'Sock C',2);

        $first=$this->order($provider,$offerA,'active','20260000401',[
            'confirmed_start_date'=>'2026-09-20',
            'proposed_start_date'=>'2026-09-20',
            'activation_date'=>'2026-09-20',
            'start_date'=>'2026-09-21',
            'end_date'=>'2026-09-23',
        ]);

        $second=$this->order($provider,$offerB,'approved','20260000402',[
            'confirmed_start_date'=>'2026-09-24',
            'proposed_start_date'=>'2026-09-24',
        ]);

        $third=$this->order($provider,$offerC,'approved','20260000403',[
            'confirmed_start_date'=>'2026-09-27',
            'proposed_start_date'=>'2026-09-27',
        ]);

        $first->update(['end_date'=>'2026-09-25']);

        app(OrderService::class)->shiftSockFollowers($first);

        $second->refresh();
        $third->refresh();

        $this->assertSame('2026-09-26',$second->confirmed_start_date?->toDateString());
        $this->assertSame('2026-09-26',$second->proposed_start_date?->toDateString());

        $this->assertSame('2026-09-29',$third->confirmed_start_date?->toDateString());
        $this->assertSame('2026-09-29',$third->proposed_start_date?->toDateString());

        $this->assertSame('approved',$second->status);
        $this->assertSame('approved',$third->status);

        $this->assertDatabaseHas('order_status_history',[
            'order_id'=>$second->id,
            'to_status'=>'approved',
        ]);
        $this->assertDatabaseHas('order_status_history',[
            'order_id'=>$third->id,
            'to_status'=>'approved',
        ]);

        $this->assertDatabaseHas('user_notifications',[
            'user_id'=>$provider->id,
            'type'=>'sock_schedule_shifted',
        ]);
        $this->assertDatabaseHas('user_notifications',[
            'user_id'=>$admin->id,
            'type'=>'sock_schedule_shifted_admin',
        ]);
    }

    public function test_waitlist_fifo_skips_temporarily_ineligible_user_without_losing_position(): void
    {
        Mail::fake();

        $category=$this->category();
        $offer=$this->sockOffer($category,'FIFO Offer',2);
        $offer->update(['capacity'=>1]);

        $first=$this->provider('fifo-first@example.test');
        $second=$this->provider('fifo-second@example.test');
        $third=$this->provider('fifo-third@example.test');

        $first->update(['status'=>'inactive']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 10:00:00','Europe/Berlin'));
        $entry1=OfferWaitlistEntry::create([
            'offer_id'=>$offer->id,
            'user_id'=>$first->id,
            'status'=>'waiting',
            'planned_start_date'=>'2026-10-01',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 10:01:00','Europe/Berlin'));
        $entry2=OfferWaitlistEntry::create([
            'offer_id'=>$offer->id,
            'user_id'=>$second->id,
            'status'=>'waiting',
            'planned_start_date'=>'2026-10-04',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 10:02:00','Europe/Berlin'));
        $entry3=OfferWaitlistEntry::create([
            'offer_id'=>$offer->id,
            'user_id'=>$third->id,
            'status'=>'waiting',
            'planned_start_date'=>'2026-10-07',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 11:00:00','Europe/Berlin'));

        try{
            $reserved=app(WaitlistService::class)->allocateNext($offer,app(NotificationService::class));

            $this->assertSame($entry2->id,$reserved?->id);
            $this->assertSame('waiting',$entry1->fresh()->status);
            $this->assertSame('reserved',$entry2->fresh()->status);
            $this->assertSame('waiting',$entry3->fresh()->status);

            $entry2->update([
                'status'=>'removed',
                'reserved_at'=>null,
                'reservation_expires_at'=>null,
            ]);
            $first->update(['status'=>'active']);

            $reservedNext=app(WaitlistService::class)->allocateNext($offer->fresh(),app(NotificationService::class));

            $this->assertSame($entry1->id,$reservedNext?->id);
            $this->assertSame('reserved',$entry1->fresh()->status);
            $this->assertSame('waiting',$entry3->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_first_invalid_day_appends_replacement_and_second_interruption_restarts_full_series(): void
    {
        Mail::fake();

        $provider=$this->provider('series-reset@example.test');
        $category=$this->category();
        $offer=$this->sockOffer($category,'Series Reset',3);

        $order=$this->order($provider,$offer,'active','20260000410',[
            'confirmed_start_date'=>'2026-09-19',
            'activation_date'=>'2026-09-19',
            'start_date'=>'2026-09-20',
            'end_date'=>'2026-09-22',
            'series_number'=>1,
            'series_interruptions'=>0,
        ]);

        $day1=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>'2026-09-20',
            'required_proofs'=>1,
            'status'=>'accepted',
            'counts_toward_series'=>true,
        ]);
        $day2=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>2,
            'series_number'=>1,
            'date'=>'2026-09-21',
            'required_proofs'=>1,
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);
        OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>3,
            'series_number'=>1,
            'date'=>'2026-09-22',
            'required_proofs'=>1,
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);

        app(OrderService::class)->invalidateDay($day2,'Erste Unterbrechung');

        $order->refresh();
        $this->assertSame(1,(int)$order->series_number);
        $this->assertSame(1,(int)$order->series_interruptions);
        $this->assertSame('accepted',$day1->fresh()->status);
        $this->assertTrue((bool)$day1->fresh()->counts_toward_series);
        $this->assertSame('2026-09-23',$order->end_date?->toDateString());

        $replacement=$order->days()
            ->where('series_number',1)
            ->where('day_number',4)
            ->firstOrFail();

        $this->assertSame('2026-09-23',$replacement->date->toDateString());
        $this->assertTrue((bool)$replacement->counts_toward_series);

        app(OrderService::class)->invalidateDay($replacement,'Zweite Unterbrechung');

        $order->refresh();
        $this->assertSame(2,(int)$order->series_number);
        $this->assertSame(0,(int)$order->series_interruptions);
        $this->assertSame('2026-09-24',$order->start_date?->toDateString());
        $this->assertSame('2026-09-26',$order->end_date?->toDateString());

        $oldSeries=$order->days()->where('series_number',1)->get();
        $this->assertTrue($oldSeries->every(fn($day)=>!$day->counts_toward_series));

        $newSeries=$order->days()
            ->where('series_number',2)
            ->orderBy('day_number')
            ->get();

        $this->assertCount(3,$newSeries);
        $this->assertSame([1,2,3],$newSeries->pluck('day_number')->map(fn($n)=>(int)$n)->all());
        $this->assertSame(
            ['2026-09-24','2026-09-25','2026-09-26'],
            $newSeries->pluck('date')->map(fn($date)=>$date->toDateString())->all()
        );
        $this->assertTrue($newSeries->every(fn($day)=>$day->counts_toward_series));
    }


    private function provider(string $email): User
    {
        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Plan',
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
            'username'=>'schedule.admin',
            'first_name'=>'Admin',
            'last_name'=>'Plan',
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
            ['slug'=>'scheduling-tests'],
            ['name'=>'Scheduling Tests','active'=>true]
        );
    }

    private function sockOffer(Category $category, string $title, int $duration): Offer
    {
        return Offer::create([
            'category_id'=>$category->id,
            'title'=>$title,
            'slug'=>strtolower(str_replace(' ','-',$title)).'-'.uniqid(),
            'base_compensation'=>40,
            'duration_days'=>$duration,
            'minimum_minutes_per_day'=>0,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'tracking_mode'=>'optional',
            'requires_precheck'=>false,
            'is_sock_wearing'=>true,
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

    private function order(User $provider, Offer $offer, string $status, string $number, array $extra=[]): Order
    {
        return Order::create(array_merge([
            'order_number'=>$number,
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>$status,
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'offer_id'=>$offer->id,
                'title'=>$offer->title,
                'duration_days'=>(int)$offer->duration_days,
                'is_sock_wearing'=>true,
            ],
            'series_number'=>1,
        ],$extra));
    }
}
