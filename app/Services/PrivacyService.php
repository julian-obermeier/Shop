<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PrivacyService
{
    public function blockingReasons(User $user): array
    {
        $reasons=[];

        $activeOrders=$user->orders()
            ->whereNotIn('status',['completed','cancelled','rejected','request_rejected','not_started'])
            ->count();

        if($activeOrders>0) $reasons[]=$activeOrders.' laufende bzw. noch nicht abgeschlossene Aufträge';

        $wallet=$user->walletAccount;
        if($wallet){
            foreach(['pending','available','payout_pending'] as $bucket){
                $balance=round($wallet->balance($bucket),2);
                if(abs($balance)>0.009){
                    $reasons[]='Wallet-Bereich '.$bucket.' hat noch '.number_format($balance,2,',','.').' €';
                }
            }
        }

        $openPayouts=$user->payouts()
            ->whereIn('status',['requested','review','approved','failed','payment_executed'])
            ->count();

        if($openPayouts>0) $reasons[]=$openPayouts.' offene Auszahlungsanträge';

        return $reasons;
    }

    public function export(User $user): array
    {
        $user->load([
            'profile',
            'orders.options',
            'orders.fieldValues',
            'orders.statusHistory',
            'orders.shipment.evidences',
            'orders.goodsReceipt',
            'orders.goodsInspection',
            'orders.returnRequest',
            'walletAccount.entries',
            'payouts',
            'documentConsents.version.document',
            'conversations.messages',
            'warnings',
            'restrictions',
            'reliabilityEvents',
        ]);

        return [
            'exported_at'=>now()->toIso8601String(),
            'account'=>[
                'id'=>$user->id,
                'first_name'=>$user->first_name,
                'last_name'=>$user->last_name,
                'birth_date'=>$user->birth_date?->toDateString(),
                'email'=>$user->email,
                'email_verified_at'=>$user->email_verified_at?->toIso8601String(),
                'status'=>$user->status,
                'created_at'=>$user->created_at?->toIso8601String(),
            ],
            'profile'=>$user->profile?->toArray(),
            'orders'=>$user->orders->map(fn($order)=>[
                'order_number'=>$order->order_number,
                'status'=>$order->status,
                'compensation_total'=>$order->compensation_total,
                'final_compensation'=>$order->final_compensation,
                'offer_snapshot'=>$order->offer_snapshot,
                'proposed_start_date'=>$order->proposed_start_date?->toDateString(),
                'confirmed_start_date'=>$order->confirmed_start_date?->toDateString(),
                'start_date'=>$order->start_date?->toDateString(),
                'end_date'=>$order->end_date?->toDateString(),
                'shipping_due_at'=>$order->shipping_due_at?->toIso8601String(),
                'options'=>$order->options->toArray(),
                'fields'=>$order->fieldValues->toArray(),
                'status_history'=>$order->statusHistory->toArray(),
                'shipment'=>$order->shipment?->toArray(),
                'shipment_evidences'=>$order->shipment?->evidences?->toArray() ?? [],
                'goods_receipt'=>$order->goodsReceipt?->toArray(),
                'goods_inspection'=>$order->goodsInspection?->toArray(),
                'return_request'=>$order->returnRequest?->toArray(),
            ])->values()->all(),
            'wallet_entries'=>$user->walletAccount?->entries?->toArray() ?? [],
            'payouts'=>$user->payouts->toArray(),
            'document_consents'=>$user->documentConsents->map(fn($consent)=>[
                'document'=>$consent->version?->document?->title,
                'version'=>$consent->version?->version,
                'consented_at'=>$consent->consented_at?->toIso8601String(),
            ])->values()->all(),
            'messages'=>$user->conversations->map(fn($conversation)=>[
                'subject'=>$conversation->subject,
                'status'=>$conversation->status,
                'order_id'=>$conversation->order_id,
                'messages'=>$conversation->messages->map(fn($message)=>[
                    'sender_user_id'=>$message->user_id,
                    'body'=>$message->body,
                    'attachment_name'=>$message->attachment_original_name,
                    'created_at'=>$message->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'warnings'=>$user->warnings->toArray(),
            'restrictions'=>$user->restrictions->toArray(),
            'reliability_events'=>$user->reliabilityEvents->toArray(),
        ];
    }

    public function anonymize(User $user): void
    {
        $blockers=$this->blockingReasons($user);
        abort_if($blockers!==[],422,'Anonymisierung derzeit nicht möglich: '.implode('; ',$blockers));

        DB::transaction(function() use($user){
            $originalEmail=$user->email;

            $user->load([
                'orders.precheck',
                'orders.days.proofs',
                'orders.fieldValues',
                'conversations.messages',
                'profile',
                'warnings',
                'restrictions',
                'userNotifications',
                'pushSubscriptions',
                'waitlistEntries',
            ]);

            // Nachweisdateien, Vorprüfungsbilder und andere auftragsbezogene Dateien
            // bleiben gemäß MASTERPROMPT dauerhaft erhalten. Die persönliche Kontozuordnung
            // wird stattdessen durch Anonymisierung der Stammdaten entpersonalisiert.
            foreach($user->orders as $order){
                foreach($order->fieldValues as $field){
                    $field->update(['value'=>null]);
                }
            }

            foreach($user->conversations as $conversation){
                foreach($conversation->messages as $message){
                    if($message->user_id===$user->id){
                        $message->update(['body'=>'[nach Kontolöschung anonymisiert]']);
                    }
                }
            }

            $user->profile?->update([
                'phone'=>null,
                'street'=>null,
                'postal_code'=>null,
                'city'=>null,
                'country_code'=>'DE',
                'bank_iban'=>null,
                'bank_account_holder'=>null,
                'paypal_email'=>null,
                'paypal_name'=>null,
                'payout_details_changed_at'=>null,
                'payout_name_approved_at'=>null,
            ]);

            $user->warnings()->delete();
            $user->restrictions()->delete();
            $user->userNotifications()->delete();
            $user->pushSubscriptions()->delete();
            $user->waitlistEntries()
                ->whereIn('status',['waiting','reserved'])
                ->update([
                    'status'=>'removed',
                    'reservation_expires_at'=>null,
                    'reservation_remaining_seconds'=>null,
                ]);

            if(Schema::hasTable('sessions')){
                DB::table('sessions')->where('user_id',$user->id)->delete();
            }

            if(Schema::hasTable('password_reset_tokens')){
                DB::table('password_reset_tokens')->where('email',$originalEmail)->delete();
            }

            $user->update([
                'first_name'=>'Gelöscht',
                'last_name'=>'Benutzer',
                'birth_date'=>'1970-01-01',
                'email'=>'deleted+'.$user->id.'+'.Str::lower(Str::random(16)).'@invalid.local',
                'password'=>Hash::make(Str::random(64)),
                'status'=>'deleted',
                'deactivated_at'=>now(),
                'deactivation_reason'=>'Konto durch Admin anonymisiert',
            ]);

            $user->forceFill([
                'email_verified_at'=>null,
                'remember_token'=>Str::random(60),
            ])->save();
        });
    }
}
