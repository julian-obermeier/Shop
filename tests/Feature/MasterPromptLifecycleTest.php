<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\ProofSubmission;
use App\Models\User;
use App\Models\WalletAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterPromptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_account_deletion_keeps_permanent_order_proof_file(): void
    {
        Storage::fake('proofs');

        $provider=$this->provider('delete@example.test');
        $admin=$this->admin('admin-delete@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Deletion Evidence');

        $order=Order::create([
            'order_number'=>'20260000301',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'completed',
            'compensation_total'=>40,
            'final_compensation'=>40,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
            'completed_at'=>now(),
        ]);

        $day=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->toDateString(),
            'required_proofs'=>1,
            'status'=>'accepted',
            'counts_toward_series'=>true,
        ]);

        Storage::disk('proofs')->put('permanent/proof.jpg','proof-bytes');

        $proof=ProofSubmission::create([
            'order_day_id'=>$day->id,
            'user_id'=>$provider->id,
            'type'=>'photo',
            'window_key'=>'daily',
            'storage_path'=>'permanent/proof.jpg',
            'original_name'=>'proof.jpg',
            'mime_type'=>'image/jpeg',
            'file_size'=>11,
            'sha256'=>hash('sha256','proof-bytes'),
            'review_status'=>'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.users.delete-account',$provider),[
            'confirm'=>'1',
            'reason'=>'Test der Admin-Kontolöschung',
        ])->assertRedirect(route('admin.users.index'));

        Storage::disk('proofs')->assertExists('permanent/proof.jpg');
        $this->assertSame('accepted',$proof->fresh()->review_status);
        $this->assertSame('permanent/proof.jpg',$proof->fresh()->storage_path);
        $this->assertSame('deleted',$provider->fresh()->status);
        $this->assertSame('Gelöscht',$provider->fresh()->first_name);
    }

    public function test_proof_challenge_is_valid_for_exactly_ten_minutes(): void
    {
        $now=CarbonImmutable::parse('2026-09-18 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('challenge@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Challenge Offer');

            $order=Order::create([
                'order_number'=>'20260000302',
                'user_id'=>$provider->id,
                'offer_id'=>$offer->id,
                'status'=>'active',
                'compensation_total'=>40,
                'offer_snapshot'=>[
                    'title'=>$offer->title,
                    'duration_days'=>1,
                    'proof_requirements'=>[[
                        'key'=>'daily',
                        'label'=>'Tagesnachweis',
                        'start'=>'00:00',
                        'end'=>'23:59',
                        'required_images'=>1,
                    ]],
                ],
                'current_requirements'=>[
                    'proof_requirements'=>[[
                        'key'=>'daily',
                        'label'=>'Tagesnachweis',
                        'start'=>'00:00',
                        'end'=>'23:59',
                        'required_images'=>1,
                    ]],
                ],
                'series_number'=>1,
            ]);

            $day=OrderDay::create([
                'order_id'=>$order->id,
                'day_number'=>1,
                'series_number'=>1,
                'date'=>$now->toDateString(),
                'required_proofs'=>1,
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);

            $this->actingAs($provider)->post(route('proofs.challenge',$day),[
                'window_key'=>'daily',
            ])->assertRedirect();

            $challenge=$order->proofChallenges()->firstOrFail();
            $this->assertSame(600,$now->diffInSeconds($challenge->expires_at,false));
            $this->assertNull($challenge->used_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_offer_deactivation_freezes_and_reactivation_resumes_waitlist_reservation(): void
    {
        $now=CarbonImmutable::parse('2026-09-18 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('waitlist-freeze@example.test');
            $admin=$this->admin('admin-offer@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Freeze Offer');

            $entry=OfferWaitlistEntry::create([
                'offer_id'=>$offer->id,
                'user_id'=>$provider->id,
                'status'=>'reserved',
                'reserved_at'=>$now,
                'reservation_expires_at'=>$now->addHours(12),
            ]);

            $payload=$this->offerPayload($offer,$category,false);

            $this->actingAs($admin)->put(route('admin.offers.update',$offer),$payload)
                ->assertRedirect();

            $entry->refresh();
            $this->assertNull($entry->reservation_expires_at);
            $this->assertGreaterThanOrEqual(43199,(int)$entry->reservation_remaining_seconds);
            $this->assertLessThanOrEqual(43200,(int)$entry->reservation_remaining_seconds);

            $payload=$this->offerPayload($offer->fresh(),$category,true);

            $this->actingAs($admin)->put(route('admin.offers.update',$offer->fresh()),$payload)
                ->assertRedirect();

            $entry->refresh();
            $this->assertNull($entry->reservation_remaining_seconds);
            $this->assertNotNull($entry->reservation_expires_at);
            $this->assertSame(43200,$now->diffInSeconds($entry->reservation_expires_at,false));
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
            'first_name'=>'Admin',
            'last_name'=>'Konto',
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
            ['slug'=>'lifecycle-tests'],
            ['name'=>'Lifecycle Tests','active'=>true]
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
                'text_required'=>false,
                'face_required'=>false,
            ]],
            'inspection_config'=>[
                'categories'=>[],
                'points_affect_compensation'=>false,
                'score_bands'=>[],
                'start_face_required'=>false,
            ],
        ]);
    }

    private function offerPayload(Offer $offer, Category $category, bool $active): array
    {
        return [
            'category_id'=>$category->id,
            'title'=>$offer->title,
            'short_description'=>$offer->short_description,
            'description'=>$offer->description,
            'base_compensation'=>(float)$offer->base_compensation,
            'duration_days'=>(int)$offer->duration_days,
            'minimum_minutes_per_day'=>(int)$offer->minimum_minutes_per_day,
            'tracking_mode'=>'optional',
            'proof_windows'=>[[
                'key'=>'daily',
                'label'=>'Tagesnachweis',
                'start'=>'00:00',
                'end'=>'23:59',
                'required_images'=>1,
            ]],
            'active'=>$active?'1':null,
        ];
    }
}
