<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeadlineMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_shipping_deadline_moves_order_to_overdue(): void
    {
        Mail::fake();

        $user=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Beispiel',
            'birth_date'=>'1995-01-01',
            'email'=>'deadline@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
            'verified_at'=>now(),
        ]);

        $category=Category::create([
            'name'=>'Socken',
            'slug'=>'deadline-socken',
            'active'=>true,
        ]);

        $offer=Offer::create([
            'category_id'=>$category->id,
            'title'=>'Deadline-Test',
            'slug'=>'deadline-test',
            'base_compensation'=>30,
            'duration_days'=>1,
            'minimum_minutes_per_day'=>60,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'requires_precheck'=>false,
            'active'=>true,
        ]);

        $order=Order::create([
            'order_number'=>'20260000001',
            'user_id'=>$user->id,
            'offer_id'=>$offer->id,
            'status'=>'waiting_shipping',
            'compensation_total'=>30,
            'offer_snapshot'=>[
                'title'=>'Deadline-Test',
                'shipping_deadline_hours'=>24,
            ],
            'accepted_at'=>now()->subDays(2),
            'completed_at'=>now()->subDay(),
            'shipping_due_at'=>now()->subMinute(),
        ]);

        $this->artisan('orders:deadlines')->assertSuccessful();

        $this->assertSame('shipping_overdue',$order->fresh()->status);
        $this->assertDatabaseHas('order_status_history',[
            'order_id'=>$order->id,
            'from_status'=>'waiting_shipping',
            'to_status'=>'shipping_overdue',
        ]);
        $this->assertDatabaseHas('user_notifications',[
            'user_id'=>$user->id,
            'type'=>'shipping_overdue',
        ]);
    }
}
