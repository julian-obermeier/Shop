<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferWaitlistEntry;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\ProofSubmission;
use App\Models\ProofChallenge;
use App\Models\User;
use App\Models\WalletAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            $this->assertEquals(600,$now->diffInSeconds($challenge->expires_at,false));
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
            $this->assertEquals(43200,$now->diffInSeconds($entry->reservation_expires_at,false));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    public function test_execution_cannot_finish_until_start_photo_and_required_days_are_accepted(): void
    {
        $provider=$this->provider('execution-proof@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Execution Proof Gate');

        $order=Order::create([
            'order_number'=>'20260000303',
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
            'series_number'=>1,
            'start_date'=>now('Europe/Berlin')->toDateString(),
            'end_date'=>now('Europe/Berlin')->toDateString(),
        ]);

        $startDay=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>0,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->subDay()->toDateString(),
            'required_proofs'=>1,
            'status'=>'activation',
            'counts_toward_series'=>true,
        ]);

        OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->toDateString(),
            'required_proofs'=>1,
            'status'=>'accepted',
            'counts_toward_series'=>true,
        ]);

        $this->actingAs($provider)
            ->post(route('orders.complete',$order))
            ->assertStatus(422);

        $this->assertSame('active',$order->fresh()->status);

        $startDay->update(['status'=>'accepted']);

        $this->actingAs($provider)
            ->post(route('orders.complete',$order))
            ->assertRedirect();

        $this->assertSame('waiting_shipping',$order->fresh()->status);
        $this->assertNotNull($order->fresh()->execution_completed_at);
        $this->assertNotNull($order->fresh()->shipping_due_at);
    }

    public function test_final_inspection_readiness_requires_accepted_shipping_evidence_and_complete_receipt(): void
    {
        $provider=$this->provider('inspection-gate@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Inspection Gate');

        $order=Order::create([
            'order_number'=>'20260000304',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'inspection',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
            ],
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

        $shipment=$order->shipment()->create([
            'carrier'=>'DHL',
            'status'=>'shipped',
            'review_status'=>'pending',
            'shipped_at'=>now()->subDay(),
        ]);

        $order->goodsReceipt()->create([
            'received_by'=>null,
            'status'=>'received',
            'complete'=>true,
            'received_at'=>now(),
        ]);

        $this->assertFalse($order->fresh()->readyForFinalInspection());

        $shipment->update(['review_status'=>'accepted']);

        $this->assertTrue($order->fresh()->readyForFinalInspection());
    }

    public function test_completed_return_persists_tracking_and_returned_timestamp(): void
    {
        $provider=$this->provider('return-track@example.test');
        $admin=$this->admin('admin-return-track@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Return Tracking');

        $order=Order::create([
            'order_number'=>'20260000305',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'rejected',
            'compensation_total'=>40,
            'final_compensation'=>0,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
            'completed_at'=>now(),
        ]);

        $return=$order->returnRequest()->create([
            'user_id'=>$provider->id,
            'status'=>'ready',
            'requested_at'=>now()->subHour(),
            'fulfillment_due_at'=>now()->addHours(23),
            'method'=>'own_label',
        ]);

        $this->actingAs($admin)->post(route('admin.returns.complete',$return),[
            'tracking_number'=>'RET-123456',
        ])->assertRedirect();

        $return->refresh();
        $this->assertSame('returned',$return->status);
        $this->assertSame('RET-123456',$return->tracking_number);
        $this->assertNotNull($return->returned_at);
    }


    public function test_additional_proof_fields_are_server_required_and_stored(): void
    {
        Storage::fake('proofs');

        $provider=$this->provider('proof-fields@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Proof Fields');

        $requirements=[[
            'key'=>'evening',
            'label'=>'Abendnachweis',
            'start'=>'00:00',
            'end'=>'23:59',
            'required_images'=>1,
            'text_required'=>false,
            'face_required'=>false,
            'image_requirements'=>'Artikel und Code müssen vollständig sichtbar sein.',
            'required_fields'=>[
                ['key'=>'aktivitaet','label'=>'Aktivität'],
                ['key'=>'umgebung','label'=>'Umgebung'],
            ],
        ]];

        $order=Order::create([
            'order_number'=>'20260000306',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'active',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
                'proof_requirements'=>$requirements,
            ],
            'current_requirements'=>[
                'proof_requirements'=>$requirements,
            ],
            'series_number'=>1,
        ]);

        $day=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->toDateString(),
            'required_proofs'=>1,
            'status'=>'open',
            'counts_toward_series'=>true,
        ]);

        $challenge=ProofChallenge::create([
            'order_id'=>$order->id,
            'order_day_id'=>$day->id,
            'user_id'=>$provider->id,
            'purpose'=>'daily',
            'window_key'=>'evening',
            'code'=>'ABC123',
            'expires_at'=>now()->addMinutes(10),
        ]);

        $this->actingAs($provider)->post(route('proofs.store',$day),[
            'proof'=>UploadedFile::fake()->image('proof.jpg',800,600),
            'challenge_id'=>$challenge->id,
            'proof_code'=>'ABC123',
            'window_key'=>'evening',
            'proof_data'=>[
                'aktivitaet'=>'Spaziergang',
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('proof_submissions',0);
        $this->assertNull($challenge->fresh()->used_at);

        $this->actingAs($provider)->post(route('proofs.store',$day),[
            'proof'=>UploadedFile::fake()->image('proof.jpg',800,600),
            'challenge_id'=>$challenge->id,
            'proof_code'=>'ABC123',
            'window_key'=>'evening',
            'proof_data'=>[
                'aktivitaet'=>'Spaziergang',
                'umgebung'=>'Draußen',
            ],
        ])->assertRedirect();

        $proof=$day->proofs()->firstOrFail();
        $this->assertSame('Spaziergang',$proof->proof_data['aktivitaet']['value']);
        $this->assertSame('Aktivität',$proof->proof_data['aktivitaet']['label']);
        $this->assertSame('Draußen',$proof->proof_data['umgebung']['value']);
        $this->assertNotNull($challenge->fresh()->used_at);
    }


    public function test_late_technical_start_proof_rejection_can_be_resubmitted_while_order_is_active(): void
    {
        Storage::fake('proofs');

        $now=CarbonImmutable::parse('2026-09-19 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('late-start-resubmit@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Late Start Resubmission');

            $order=Order::create([
                'order_number'=>'20260000307',
                'user_id'=>$provider->id,
                'offer_id'=>$offer->id,
                'status'=>'active',
                'compensation_total'=>40,
                'offer_snapshot'=>[
                    'title'=>$offer->title,
                    'duration_days'=>1,
                    'inspection_config'=>['start_face_required'=>false],
                ],
                'current_requirements'=>[
                    'inspection_config'=>['start_face_required'=>false],
                ],
                'series_number'=>1,
                'activation_date'=>$now->subDay()->toDateString(),
                'start_date'=>$now->toDateString(),
                'end_date'=>$now->toDateString(),
            ]);

            $startDay=OrderDay::create([
                'order_id'=>$order->id,
                'day_number'=>0,
                'series_number'=>1,
                'date'=>$now->subDay()->toDateString(),
                'required_proofs'=>1,
                'status'=>'activation',
                'counts_toward_series'=>true,
            ]);

            ProofSubmission::create([
                'order_day_id'=>$startDay->id,
                'user_id'=>$provider->id,
                'type'=>'start_photo',
                'window_key'=>'start',
                'storage_path'=>'old-start.jpg',
                'original_name'=>'old-start.jpg',
                'mime_type'=>'image/jpeg',
                'file_size'=>100,
                'sha256'=>str_repeat('a',64),
                'retry_number'=>0,
                'review_status'=>'rejected',
                'rejection_kind'=>'technical',
                'review_comment'=>'Technisch nicht eindeutig',
                'resubmit_due_at'=>$now->addHours(2),
                'reviewed_at'=>$now,
            ]);

            $this->actingAs($provider)->post(route('proofs.challenge',$startDay),[
                'window_key'=>'start',
            ])->assertRedirect();

            $challenge=$order->proofChallenges()->latest('id')->firstOrFail();
            $this->assertTrue($challenge->expires_at->isFuture());

            $this->actingAs($provider)->post(route('proofs.store',$startDay),[
                'proof'=>UploadedFile::fake()->image('start-new.jpg',800,600),
                'challenge_id'=>$challenge->id,
                'proof_code'=>$challenge->code,
                'window_key'=>'start',
            ])->assertRedirect();

            $order->refresh();
            $this->assertSame('active',$order->status);
            $this->assertSame(1,$order->series_number);

            $newProof=$startDay->proofs()->latest('id')->firstOrFail();
            $this->assertSame(1,(int)$newProof->retry_number);
            $this->assertSame('pending',$newProof->review_status);
            $this->assertNotNull($challenge->fresh()->used_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_archived_series_day_cannot_generate_new_proof_challenge(): void
    {
        $provider=$this->provider('archived-proof@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Archived Proof');

        $order=Order::create([
            'order_number'=>'20260000308',
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
            'series_number'=>2,
        ]);

        $archivedDay=OrderDay::create([
            'order_id'=>$order->id,
            'day_number'=>1,
            'series_number'=>1,
            'date'=>now('Europe/Berlin')->toDateString(),
            'required_proofs'=>1,
            'status'=>'open',
            'counts_toward_series'=>false,
        ]);

        $this->actingAs($provider)->post(route('proofs.challenge',$archivedDay),[
            'window_key'=>'daily',
        ])->assertStatus(422);

        $this->assertDatabaseCount('proof_challenges',0);
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
