<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MasterPromptMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_chat_notifies_counterpart_stays_writable_seven_days_and_then_locks(): void
    {
        Mail::fake();

        $now=CarbonImmutable::parse('2026-09-19 10:00:00','Europe/Berlin');
        CarbonImmutable::setTestNow($now);

        try{
            [$provider,$admin,$order]=$this->chatContext($now->subDays(6));

            $this->actingAs($provider)->post(route('messages.store'),[
                'order_id'=>$order->id,
                'message'=>'Nachricht der Anbieterin',
            ])->assertRedirect();

            $conversation=$provider->conversations()->where('order_id',$order->id)->firstOrFail();
            $providerMessage=$conversation->messages()->firstOrFail();

            $this->assertDatabaseHas('user_notifications',[
                'user_id'=>$admin->id,
                'type'=>'order_message',
            ]);

            $this->actingAs($admin)->post(route('admin.messages.reply',$conversation),[
                'message'=>'Antwort des Admins',
            ])->assertRedirect();

            $this->assertDatabaseHas('user_notifications',[
                'user_id'=>$provider->id,
                'type'=>'order_message',
            ]);
            $this->assertSame(2,$conversation->messages()->count());

            $providerMessage->update(['read_at'=>now()]);
            $this->assertNotNull($providerMessage->fresh()->read_at);

            try{
                $providerMessage->update(['body'=>'Manipulierter Text']);
                $this->fail('Gesendeter Nachrichtentext konnte normal verändert werden.');
            }catch(\LogicException $e){
                $this->assertStringContainsString('unveränderlich',$e->getMessage());
            }

            try{
                $providerMessage->delete();
                $this->fail('Gesendete Nachricht konnte gelöscht werden.');
            }catch(\LogicException $e){
                $this->assertStringContainsString('nicht gelöscht',$e->getMessage());
            }

            $providerMessage->anonymizeForDeletedUser();
            $this->assertSame('[nach Kontolöschung anonymisiert]',$providerMessage->fresh()->body);

            CarbonImmutable::setTestNow($order->completed_at->copy()->addDays(7)->addSecond());

            $this->actingAs($provider)->post(route('messages.reply',$conversation),[
                'message'=>'Zu spät',
            ])->assertStatus(422);

            $this->actingAs($admin)->post(route('admin.messages.reply',$conversation),[
                'message'=>'Auch zu spät',
            ])->assertStatus(422);

            $this->assertSame(2,$conversation->messages()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function chatContext(CarbonImmutable $completedAt): array
    {
        $provider=User::create([
            'role'=>'provider',
            'first_name'=>'Anna',
            'last_name'=>'Chat',
            'birth_date'=>'1995-01-01',
            'email'=>'chat-provider@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $provider->forceFill(['email_verified_at'=>now()])->save();
        $provider->profile()->create();

        $admin=User::create([
            'role'=>'admin',
            'username'=>'chat.admin',
            'first_name'=>'Admin',
            'last_name'=>'Chat',
            'birth_date'=>'1970-01-01',
            'email'=>'chat-admin@example.test',
            'password'=>Hash::make('VerySecurePassword123!'),
            'status'=>'active',
        ]);
        $admin->forceFill(['email_verified_at'=>now()])->save();

        $category=Category::create([
            'name'=>'Chat Tests',
            'slug'=>'chat-tests',
            'active'=>true,
        ]);

        $offer=Offer::create([
            'category_id'=>$category->id,
            'title'=>'Chat Angebot',
            'slug'=>'chat-angebot',
            'base_compensation'=>40,
            'duration_days'=>1,
            'proofs_per_day'=>1,
            'shipping_deadline_hours'=>24,
            'tracking_mode'=>'optional',
            'active'=>true,
        ]);

        $order=Order::create([
            'order_number'=>'20260000601',
            'user_id'=>$provider->id,
            'offer_id'=>$offer->id,
            'status'=>'completed',
            'compensation_total'=>40,
            'final_compensation'=>40,
            'offer_snapshot'=>[
                'title'=>$offer->title,
                'duration_days'=>1,
            ],
            'completed_at'=>$completedAt,
        ]);

        return [$provider,$admin,$order];
    }
}
