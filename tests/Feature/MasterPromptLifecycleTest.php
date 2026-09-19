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
use Illuminate\Support\Facades\Mail;
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


    public function test_reliability_restriction_does_not_block_required_existing_order_file_workflows(): void
    {
        Mail::fake();
        Storage::fake('shipments');
        Storage::fake('messages');
        Storage::fake('returns');

        $provider=$this->provider('reliability-files@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Reliability File Workflows');

        $provider->restrictions()->create([
            'issued_by'=>null,
            'type'=>'reliability',
            'reason'=>'Neue Aufträge eingeschränkt, bestehende Pflichten bleiben erfüllbar.',
            'starts_at'=>now()->subMinute(),
            'active'=>true,
            'required_successes'=>5,
            'successful_count'=>0,
            'max_active_orders'=>0,
            'blocked_offer_ids'=>[$offer->id],
        ]);

        $shippingOrder=Order::create([
            'order_number'=>'20260000309',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'waiting_shipping',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
                'tracking_mode'=>'optional',
            ],
            'execution_completed_at'=>now(),
            'shipping_due_at'=>now()->addHours(24),
        ]);

        $this->actingAs($provider)->post(route('orders.shipment',$shippingOrder),[
            'carrier'=>'DHL',
            'tracking_number'=>'TRACK-1',
            'package_photo'=>UploadedFile::fake()->image('package.jpg',800,600),
            'receipt_photo'=>UploadedFile::fake()->image('receipt.jpg',800,600),
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments',[
            'order_id'=>$shippingOrder->id,
            'carrier'=>'DHL',
        ]);

        $messageOrder=Order::create([
            'order_number'=>'20260000310',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'requested',
            'compensation_total'=>40,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
        ]);

        $this->actingAs($provider)->post(route('messages.store'),[
            'order_id'=>$messageOrder->id,
            'message'=>'Anhang zu bestehendem Auftrag.',
            'attachment'=>UploadedFile::fake()->create('anlage.pdf',20,'application/pdf'),
        ])->assertRedirect();

        $message=$provider->conversations()
            ->where('order_id',$messageOrder->id)
            ->firstOrFail()
            ->messages()
            ->firstOrFail();

        $this->assertSame('Anhang zu bestehendem Auftrag.',$message->body);
        $this->assertNotNull($message->attachment_path);
        Storage::disk('messages')->assertExists($message->attachment_path);

        $returnOrder=Order::create([
            'order_number'=>'20260000311',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'rejected',
            'compensation_total'=>40,
            'final_compensation'=>0,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
            'completed_at'=>now(),
        ]);

        $returnOrder->goodsInspection()->create([
            'reviewed_by'=>null,
            'categories'=>[],
            'base_percentage'=>0,
            'extra_results'=>[],
            'calculated_compensation'=>0,
            'result'=>'rejected',
            'reason'=>'Testablehnung',
            'reviewed_at'=>now(),
        ]);

        $this->actingAs($provider)->post(route('orders.return-request',$returnOrder),[
            'method'=>'own_label',
            'return_label'=>UploadedFile::fake()->create('return.pdf',20,'application/pdf'),
        ])->assertRedirect();

        $return=$returnOrder->fresh()->returnRequest()->firstOrFail();
        $this->assertSame('own_label',$return->method);
        $this->assertSame('ready',$return->status);
        $this->assertNotNull($return->return_label_path);
        Storage::disk('returns')->assertExists($return->return_label_path);
    }


    public function test_third_technical_rejection_waits_for_manual_extra_retry_instead_of_automatic_deadline(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-18 20:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('retry-limit@example.test');
            $admin=$this->admin('admin-retry-limit@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Retry Limit');

            $requirements=[[
                'key'=>'morning',
                'label'=>'Morgennachweis',
                'start'=>'08:00',
                'end'=>'10:00',
                'required_images'=>1,
            ]];

            $order=Order::create([
                'order_number'=>'20260000312',
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
                'date'=>$now->toDateString(),
                'required_proofs'=>1,
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);

            $proof=ProofSubmission::create([
                'order_day_id'=>$day->id,
                'user_id'=>$provider->id,
                'type'=>'photo',
                'window_key'=>'morning',
                'storage_path'=>'retry-2.jpg',
                'original_name'=>'retry-2.jpg',
                'mime_type'=>'image/jpeg',
                'file_size'=>100,
                'sha256'=>str_repeat('b',64),
                'retry_number'=>2,
                'review_status'=>'pending',
            ]);

            $this->actingAs($admin)->post(route('admin.proofs.review',$proof),[
                'review_status'=>'rejected',
                'rejection_kind'=>'technical',
                'review_comment'=>'Noch einmal technisch unbrauchbar',
            ])->assertRedirect();

            $proof->refresh();
            $this->assertSame('rejected',$proof->review_status);
            $this->assertNull($proof->resubmit_due_at);
            $this->assertFalse((bool)$proof->extra_retry_granted);

            $this->artisan('orders:deadlines')->assertExitCode(0);

            $this->assertSame('open',$day->fresh()->status);
            $this->assertTrue((bool)$day->fresh()->counts_toward_series);

            $this->actingAs($admin)->post(route('admin.proofs.extra-retry',$proof))
                ->assertRedirect();

            $proof->refresh();
            $this->assertTrue((bool)$proof->extra_retry_granted);
            $this->assertNotNull($proof->resubmit_due_at);
            $this->assertEquals(7200,$now->diffInSeconds($proof->resubmit_due_at,false));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    public function test_deactivation_paused_shipped_order_can_resume_to_original_phase(): void
    {
        Mail::fake();

        $provider=$this->provider('deactivation-shipped@example.test');
        $admin=$this->admin('admin-deactivation-shipped@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Paused Shipped');

        $order=Order::create([
            'order_number'=>'20260000313',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'shipped',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
                'tracking_mode'=>'optional',
            ],
            'execution_completed_at'=>now()->subDay(),
            'shipping_due_at'=>now()->subHours(12),
        ]);

        $this->actingAs($admin)->post(route('admin.users.deactivate',$provider),[
            'reason'=>'Temporäre Kontodeaktivierung',
            'orders'=>[
                $order->id=>[
                    'action'=>'pause',
                    'reason'=>'Versandprüfung vorübergehend anhalten',
                    'compensation_amount'=>0,
                ],
            ],
        ])->assertRedirect();

        $this->assertSame('inactive',$provider->fresh()->status);
        $this->assertSame('paused',$order->fresh()->status);
        $this->assertSame('shipped',$order->fresh()->paused_from_status);

        $this->actingAs($admin)->post(route('admin.users.reactivate',$provider->fresh()))
            ->assertRedirect();

        $this->actingAs($admin)->post(route('admin.orders.resume',$order->fresh()))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('shipped',$order->status);
        $this->assertNull($order->paused_from_status);
        $this->assertNull($order->paused_at);
    }


    public function test_partial_goods_receipt_does_not_transfer_shipping_risk_until_complete_receipt(): void
    {
        Mail::fake();

        $provider=$this->provider('partial-receipt@example.test');
        $admin=$this->admin('admin-partial-receipt@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Partial Receipt');

        $order=Order::create([
            'order_number'=>'20260000314',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'shipped',
            'compensation_total'=>40,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
            'execution_completed_at'=>now()->subDay(),
        ]);

        $shipment=$order->shipment()->create([
            'carrier'=>'DHL',
            'status'=>'shipped',
            'review_status'=>'accepted',
            'shipped_at'=>now()->subDay(),
            'ownership_transferred_at'=>now()->subDay(),
        ]);

        $this->actingAs($admin)->post(route('admin.orders.goods-receipt',$order),[
            'complete'=>'0',
            'note'=>'Sendung unvollständig',
        ])->assertRedirect();

        $this->assertNull($shipment->fresh()->risk_transferred_at);
        $this->assertSame('received',$order->fresh()->status);

        $this->actingAs($admin)->post(route('admin.orders.goods-receipt',$order->fresh()),[
            'complete'=>'1',
            'note'=>'Rest vollständig eingegangen',
        ])->assertRedirect();

        $this->assertNotNull($shipment->fresh()->risk_transferred_at);
        $this->assertNotNull($shipment->fresh()->delivered_at);
        $this->assertSame('inspection',$order->fresh()->status);
    }

    public function test_final_goods_calculation_preserves_promised_additional_compensation(): void
    {
        Mail::fake();

        $provider=$this->provider('additional-comp@example.test');
        $admin=$this->admin('admin-additional-comp@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Additional Compensation');

        $order=Order::create([
            'order_number'=>'20260000315',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'inspection',
            'compensation_total'=>55,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'base_compensation'=>40,
                'duration_days'=>1,
                'inspection_config'=>[
                    'categories'=>[],
                    'points_affect_compensation'=>false,
                    'score_bands'=>[],
                ],
            ],
            'current_requirements'=>[
                'inspection_config'=>[
                    'categories'=>[],
                    'points_affect_compensation'=>false,
                    'score_bands'=>[],
                ],
                'admin_addition'=>[
                    'text'=>'Zusätzliche verbindliche Anforderung',
                    'additional_compensation'=>15,
                ],
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

        $categories=[];
        foreach(['appearance','smell','taste','proofs','extras'] as $key){
            $categories[$key]=[
                'passed'=>'1',
                'points'=>10,
                'comment'=>null,
            ];
        }

        $this->actingAs($admin)->post(route('admin.orders.goods-inspection',$order),[
            'result'=>'accepted',
            'categories'=>$categories,
            'manual_base_percentage'=>50,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('completed',$order->status);
        $this->assertEquals(35.0,(float)$order->final_compensation);
        $this->assertEquals(35.0,$provider->walletAccount()->firstOrFail()->balance('available'));

        $inspection=$order->goodsInspection()->firstOrFail();
        $this->assertEquals(15.0,(float)($inspection->categories['_additional_compensation']??0));
    }


    public function test_expired_proof_challenges_are_persistently_logged_and_new_code_can_be_generated(): void
    {
        Storage::fake('proofs');

        $now=CarbonImmutable::parse('2026-09-19 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('expired-code@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Expired Code');

            $requirements=[[
                'key'=>'daily',
                'label'=>'Tagesnachweis',
                'start'=>'00:00',
                'end'=>'23:59',
                'required_images'=>1,
                'text_required'=>false,
                'face_required'=>false,
            ]];

            $order=Order::create([
                'order_number'=>'20260000316',
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
                'date'=>$now->toDateString(),
                'required_proofs'=>1,
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);

            $expired=ProofChallenge::create([
                'order_id'=>$order->id,
                'order_day_id'=>$day->id,
                'user_id'=>$provider->id,
                'purpose'=>'daily',
                'window_key'=>'daily',
                'code'=>'OLD123',
                'expires_at'=>$now->subMinute(),
            ]);

            $this->actingAs($provider)->post(route('proofs.store',$day),[
                'proof'=>UploadedFile::fake()->image('expired.jpg',800,600),
                'challenge_id'=>$expired->id,
                'proof_code'=>'OLD123',
                'window_key'=>'daily',
            ])->assertStatus(422);

            $expired->refresh();
            $this->assertNotNull($expired->expired_at);
            $this->assertNull($expired->used_at);
            $this->assertDatabaseCount('proof_submissions',0);

            $stale=ProofChallenge::create([
                'order_id'=>$order->id,
                'order_day_id'=>$day->id,
                'user_id'=>$provider->id,
                'purpose'=>'daily',
                'window_key'=>'daily',
                'code'=>'OLD456',
                'expires_at'=>$now->subSeconds(30),
            ]);

            $this->actingAs($provider)->post(route('proofs.challenge',$day),[
                'window_key'=>'daily',
            ])->assertRedirect();

            $stale->refresh();
            $this->assertNotNull($stale->expired_at);
            $this->assertNull($stale->used_at);

            $newChallenge=ProofChallenge::where('order_day_id',$day->id)
                ->whereNull('used_at')
                ->whereNull('expired_at')
                ->latest('id')
                ->firstOrFail();

            $this->assertTrue($newChallenge->expires_at->isFuture());
            $this->assertSame('daily',$newChallenge->window_key);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    public function test_submitted_proof_and_shipment_evidence_files_are_immutable_but_review_state_remains_editable(): void
    {
        $provider=$this->provider('immutable-evidence@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Immutable Evidence');

        $order=Order::create([
            'order_number'=>'20260000317',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'active',
            'compensation_total'=>40,
            'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
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

        $proof=ProofSubmission::create([
            'order_day_id'=>$day->id,
            'user_id'=>$provider->id,
            'type'=>'photo',
            'window_key'=>'daily',
            'storage_path'=>'proof/original.jpg',
            'original_name'=>'original.jpg',
            'mime_type'=>'image/jpeg',
            'file_size'=>123,
            'sha256'=>str_repeat('c',64),
            'retry_number'=>0,
            'review_status'=>'pending',
        ]);

        $proof->update([
            'review_status'=>'accepted',
            'review_comment'=>'Inhalt geprüft',
            'reviewed_at'=>now(),
        ]);
        $this->assertSame('accepted',$proof->fresh()->review_status);

        try{
            $proof->update(['storage_path'=>'proof/manipulated.jpg']);
            $this->fail('Manipulation der Nachweisdatei wurde nicht blockiert.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('unveränderlich',$e->getMessage());
        }

        try{
            $proof->delete();
            $this->fail('Löschen eines eingereichten Nachweises wurde nicht blockiert.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('nicht gelöscht',$e->getMessage());
        }

        $shipment=$order->shipment()->create([
            'carrier'=>'DHL',
            'status'=>'shipped',
            'review_status'=>'pending',
            'shipped_at'=>now(),
            'ownership_transferred_at'=>now(),
        ]);

        $evidence=$shipment->evidences()->create([
            'user_id'=>$provider->id,
            'type'=>'package',
            'storage_path'=>'shipment/package.jpg',
            'original_name'=>'package.jpg',
            'mime_type'=>'image/jpeg',
            'file_size'=>456,
            'sha256'=>str_repeat('d',64),
            'attempt'=>0,
        ]);

        try{
            $evidence->update(['sha256'=>str_repeat('e',64)]);
            $this->fail('Manipulation des Versandnachweis-Hashes wurde nicht blockiert.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('unveränderlich',$e->getMessage());
        }

        try{
            $evidence->delete();
            $this->fail('Löschen eines eingereichten Versandnachweises wurde nicht blockiert.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('nicht gelöscht',$e->getMessage());
        }
    }


    public function test_requirement_change_audit_does_not_retain_reconstructable_old_or_new_requirement_text(): void
    {
        Mail::fake();

        $provider=$this->provider('requirements-audit@example.test');
        $admin=$this->admin('admin-requirements-audit@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Requirement Audit');

        $oldText='Alter vertraulicher Anforderungstext';
        $newText='Neuer verbindlicher Anforderungstext';

        $snapshot=[
            'title'=>$offer->title,
            'duration_days'=>1,
            'proof_requirements'=>$offer->proof_requirements,
            'inspection_config'=>$offer->inspection_config,
            'admin_addition'=>[
                'text'=>$oldText,
                'effective_mode'=>'immediately',
                'effective_at'=>now()->subDay()->toIso8601String(),
                'additional_compensation'=>0,
            ],
        ];

        $order=Order::create([
            'order_number'=>'20260000318',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'approved',
            'compensation_total'=>40,
            'offer_snapshot'=>$snapshot,
            'current_requirements'=>$snapshot,
            'confirmed_start_date'=>now('Europe/Berlin')->addDay()->toDateString(),
            'proposed_start_date'=>now('Europe/Berlin')->addDay()->toDateString(),
        ]);

        $this->actingAs($admin)->post(route('admin.orders.requirements',$order),[
            'requirement_text'=>$newText,
            'effective_mode'=>'immediately',
            'additional_compensation'=>5,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame($newText,data_get($order->current_requirements,'admin_addition.text'));
        $this->assertEquals(45.0,(float)$order->compensation_total);

        $audit=\App\Models\AuditLog::where('action','order.requirements.changed')
            ->where('auditable_id',$order->id)
            ->latest('id')
            ->firstOrFail();

        $serialized=json_encode([$audit->before,$audit->after],JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($oldText,$serialized);
        $this->assertStringNotContainsString($newText,$serialized);
        $this->assertStringNotContainsString('current_requirements',$serialized);
        $this->assertStringNotContainsString('offer_snapshot',$serialized);
        $this->assertTrue((bool)($audit->after['change_recorded']??false));
        $this->assertEquals(5.0,(float)($audit->after['additional_compensation']??0));
    }


    public function test_precheck_replacement_keeps_previous_image_history_and_record_cannot_be_deleted(): void
    {
        Storage::fake('prechecks');

        $provider=$this->provider('precheck-history@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Precheck History');
        $offer->update(['requires_precheck'=>true]);

        $order=Order::create([
            'order_number'=>'20260000319',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'precheck',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
                'requires_precheck'=>true,
            ],
        ]);

        $this->actingAs($provider)->post(route('orders.precheck',$order),[
            'item_description'=>'Erste Beschreibung',
            'item_size'=>'39',
            'item_type'=>'Socken',
            'photo'=>UploadedFile::fake()->image('first.jpg',800,600),
        ])->assertRedirect();

        $precheck=$order->fresh()->precheck()->firstOrFail();
        $firstPath=$precheck->photo_path;
        Storage::disk('prechecks')->assertExists($firstPath);

        $order->update(['status'=>'precheck_resubmit']);

        $this->actingAs($provider)->post(route('orders.precheck',$order->fresh()),[
            'item_description'=>'Zweite Beschreibung',
            'item_size'=>'39',
            'item_type'=>'Socken',
            'existing_photo'=>$firstPath,
            'photo'=>UploadedFile::fake()->image('second.jpg',800,600),
        ])->assertRedirect();

        $precheck->refresh();
        $this->assertNotSame($firstPath,$precheck->photo_path);
        Storage::disk('prechecks')->assertExists($firstPath);
        Storage::disk('prechecks')->assertExists($precheck->photo_path);
        $this->assertSame(
            $firstPath,
            data_get($precheck->answers,'photo_history.0.path')
        );

        try{
            $precheck->delete();
            $this->fail('Vorprüfungsdatensatz konnte gelöscht werden.');
        }catch(\LogicException $e){
            $this->assertStringContainsString('nicht gelöscht',$e->getMessage());
        }

        $this->assertDatabaseHas('order_prechecks',['id'=>$precheck->id]);
    }


    public function test_requirement_change_next_window_uses_earliest_future_window_even_when_config_is_unsorted(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-19 09:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('next-window-order@example.test');
            $admin=$this->admin('next-window-admin@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Next Window Ordering');

            $requirements=[
                [
                    'key'=>'afternoon',
                    'label'=>'Nachmittag',
                    'start'=>'14:00',
                    'end'=>'15:00',
                    'required_images'=>1,
                ],
                [
                    'key'=>'morning',
                    'label'=>'Vormittag',
                    'start'=>'10:00',
                    'end'=>'11:00',
                    'required_images'=>1,
                ],
            ];

            $snapshot=[
                'title'=>$offer->title,
                'duration_days'=>1,
                'proof_requirements'=>$requirements,
                'inspection_config'=>$offer->inspection_config,
            ];

            $order=Order::create([
                'order_number'=>'20260000320',
                'user_id'=>$provider->id,
                'offer_id'=>$offer->id,
                'status'=>'active',
                'compensation_total'=>40,
                'offer_snapshot'=>$snapshot,
                'current_requirements'=>$snapshot,
                'series_number'=>1,
                'start_date'=>$now->toDateString(),
                'end_date'=>$now->toDateString(),
            ]);

            OrderDay::create([
                'order_id'=>$order->id,
                'day_number'=>1,
                'series_number'=>1,
                'date'=>$now->toDateString(),
                'required_proofs'=>2,
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);

            $this->actingAs($admin)->post(route('admin.orders.requirements',$order),[
                'requirement_text'=>'Neue Anforderung ab dem tatsächlich nächsten Nachweisfenster.',
                'effective_mode'=>'next_window',
                'additional_compensation'=>0,
            ])->assertRedirect();

            $order->refresh();

            $this->assertSame(
                '2026-09-19 10:00',
                $order->requirements_effective_at?->timezone('Europe/Berlin')->format('Y-m-d H:i')
            );
            $this->assertSame(
                'next_window',
                data_get($order->current_requirements,'admin_addition.effective_mode')
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }


    public function test_precheck_replacement_preserves_previous_photo_hash_and_admin_can_open_history(): void
    {
        Storage::fake('prechecks');

        $provider=$this->provider('precheck-history@example.test');
        $admin=$this->admin('admin-precheck-history@example.test');
        $category=$this->category();
        $offer=$this->offer($category,'Precheck History');
        $offer->update(['requires_precheck'=>true]);

        $order=Order::create([
            'order_number'=>'20260000319',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'precheck',
            'compensation_total'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
                'requires_precheck'=>true,
            ],
        ]);

        $this->actingAs($provider)->post(route('orders.precheck',$order),[
            'item_description'=>'Erste Version',
            'item_type'=>'Artikel',
            'item_size'=>'M',
            'photo'=>UploadedFile::fake()->image('first.jpg',800,600),
        ])->assertRedirect();

        $precheck=$order->precheck()->firstOrFail();
        $firstPath=$precheck->photo_path;
        $firstHash=data_get($precheck->answers,'current_photo_sha256');

        Storage::disk('prechecks')->assertExists($firstPath);
        $this->assertNotEmpty($firstHash);

        $order->update(['status'=>'precheck_resubmit']);

        $this->actingAs($provider)->post(route('orders.precheck',$order->fresh()),[
            'item_description'=>'Zweite Version',
            'item_type'=>'Artikel',
            'item_size'=>'M',
            'photo'=>UploadedFile::fake()->image('second.jpg',800,600),
        ])->assertRedirect();

        $precheck->refresh();
        $history=(array)data_get($precheck->answers,'photo_history',[]);

        $this->assertCount(1,$history);
        $this->assertSame($firstPath,$history[0]['path']);
        $this->assertSame($firstHash,$history[0]['sha256']);
        Storage::disk('prechecks')->assertExists($firstPath);
        Storage::disk('prechecks')->assertExists($precheck->photo_path);

        $this->actingAs($provider)
            ->get(route('admin.prechecks.history-file',[$precheck,0]))
            ->assertStatus(403);

        $this->actingAs($admin)
            ->get(route('admin.prechecks.history-file',[$precheck,0]))
            ->assertOk();
    }


    public function test_proof_challenge_is_blocked_outside_configured_window(): void
    {
        $now=CarbonImmutable::parse('2026-09-19 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('window-guard@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Window Guard');

            $requirements=[[
                'key'=>'evening',
                'label'=>'Abendnachweis',
                'start'=>'18:00',
                'end'=>'20:00',
                'required_images'=>1,
            ]];

            $order=Order::create([
                'order_number'=>'20260000710',
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
                'date'=>$now->toDateString(),
                'required_proofs'=>1,
                'status'=>'open',
                'counts_toward_series'=>true,
            ]);

            $this->actingAs($provider)->post(route('proofs.challenge',$day),[
                'window_key'=>'evening',
            ])->assertStatus(422);

            $this->assertDatabaseCount('proof_challenges',0);

            CarbonImmutable::setTestNow($now->setTime(18,30));

            $this->actingAs($provider)->post(route('proofs.challenge',$day),[
                'window_key'=>'evening',
            ])->assertRedirect();

            $this->assertDatabaseHas('proof_challenges',[
                'order_day_id'=>$day->id,
                'window_key'=>'evening',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_admin_can_grant_multiple_extra_retries_sequentially(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-19 12:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            $provider=$this->provider('multi-extra-retry@example.test');
            $admin=$this->admin('admin-multi-extra-retry@example.test');
            $category=$this->category();
            $offer=$this->offer($category,'Multiple Extra Retry');

            $order=Order::create([
                'order_number'=>'20260000711',
                'user_id'=>$provider->id,
                'offer_id'=>$offer->id,
                'status'=>'active',
                'compensation_total'=>40,
                'offer_snapshot'=>['title'=>$offer->title,'duration_days'=>1],
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

            $firstRejected=ProofSubmission::create([
                'order_day_id'=>$day->id,
                'user_id'=>$provider->id,
                'type'=>'photo',
                'window_key'=>'daily',
                'storage_path'=>'extra-retry-2.jpg',
                'original_name'=>'extra-retry-2.jpg',
                'mime_type'=>'image/jpeg',
                'file_size'=>100,
                'sha256'=>str_repeat('f',64),
                'retry_number'=>2,
                'review_status'=>'rejected',
                'rejection_kind'=>'technical',
                'review_comment'=>'Technischer Fehler',
                'reviewed_at'=>$now,
            ]);

            $this->actingAs($admin)
                ->post(route('admin.proofs.extra-retry',$firstRejected))
                ->assertRedirect();

            $firstRejected->refresh();
            $this->assertTrue((bool)$firstRejected->extra_retry_granted);
            $this->assertEquals(7200,$now->diffInSeconds($firstRejected->resubmit_due_at,false));

            $firstRejected->update([
                'extra_retry_granted'=>false,
                'resubmit_due_at'=>null,
            ]);

            $secondRejected=ProofSubmission::create([
                'order_day_id'=>$day->id,
                'user_id'=>$provider->id,
                'type'=>'photo',
                'window_key'=>'daily',
                'storage_path'=>'extra-retry-3.jpg',
                'original_name'=>'extra-retry-3.jpg',
                'mime_type'=>'image/jpeg',
                'file_size'=>100,
                'sha256'=>str_repeat('a',64),
                'retry_number'=>3,
                'review_status'=>'rejected',
                'rejection_kind'=>'technical',
                'review_comment'=>'Noch ein technischer Fehler',
                'reviewed_at'=>$now,
            ]);

            $this->actingAs($admin)
                ->post(route('admin.proofs.extra-retry',$secondRejected))
                ->assertRedirect();

            $secondRejected->refresh();
            $this->assertTrue((bool)$secondRejected->extra_retry_granted);
            $this->assertEquals(7200,$now->diffInSeconds($secondRejected->resubmit_due_at,false));
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
