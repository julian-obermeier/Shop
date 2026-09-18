<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivacyService
{
    public function blockingReasons(User $user): array
    {
        $reasons=[];

        $activeOrders=$user->orders()
            ->whereNotIn('status',['completed','cancelled','rejected','compensation_released'])
            ->count();

        if($activeOrders>0) $reasons[]=$activeOrders.' laufende bzw. noch nicht abgeschlossene Aufträge';

        $wallet=$user->walletAccount;
        if($wallet){
            foreach(['pending','available','payout_pending'] as $bucket){
                $balance=round($wallet->balance($bucket),2);
                if(abs($balance)>0.009) $reasons[]='Wallet-Bereich '.$bucket.' hat noch '.number_format($balance,2,',','.').' €';
            }
        }

        $openPayouts=$user->payouts()
            ->whereIn('status',['requested','review','approved'])
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
            'orders.shipment',
            'orders.goodsReceipt',
            'walletAccount.entries',
            'payouts',
            'documentConsents.version.document',
            'conversations.messages',
            'warnings',
            'restrictions',
            'verifications',
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
                'identity_verified_at'=>$user->verified_at?->toIso8601String(),
                'status'=>$user->status,
                'created_at'=>$user->created_at?->toIso8601String(),
            ],
            'profile'=>$user->profile?->toArray(),
            'orders'=>$user->orders->map(fn($order)=>[
                'order_number'=>$order->order_number,
                'status'=>$order->status,
                'compensation_total'=>$order->compensation_total,
                'offer_snapshot'=>$order->offer_snapshot,
                'start_date'=>$order->start_date?->toDateString(),
                'end_date'=>$order->end_date?->toDateString(),
                'shipping_due_at'=>$order->shipping_due_at?->toIso8601String(),
                'options'=>$order->options->toArray(),
                'fields'=>$order->fieldValues->toArray(),
                'status_history'=>$order->statusHistory->toArray(),
                'shipment'=>$order->shipment?->toArray(),
                'goods_receipt'=>$order->goodsReceipt?->toArray(),
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
            'identity_verifications'=>$user->verifications->map(fn($verification)=>[
                'status'=>$verification->status,
                'method'=>$verification->method,
                'reviewed_at'=>$verification->reviewed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function anonymize(User $user): void
    {
        $blockers=$this->blockingReasons($user);
        abort_if($blockers!==[],422,'Anonymisierung derzeit nicht möglich: '.implode('; ',$blockers));

        DB::transaction(function() use($user){
            $user->load([
                'verifications',
                'orders.precheck',
                'orders.days.proofs',
                'orders.fieldValues',
                'conversations.messages',
                'profile',
                'warnings',
                'restrictions',
                'userNotifications',
            ]);

            foreach($user->verifications as $verification){
                foreach([$verification->document_front_path,$verification->document_back_path] as $path){
                    if($path) Storage::disk('identity')->delete($path);
                }
                $verification->update([
                    'document_front_path'=>null,
                    'document_back_path'=>null,
                    'admin_comment'=>null,
                ]);
            }

            foreach($user->orders as $order){
                if($order->precheck?->photo_path){
                    Storage::disk('prechecks')->delete($order->precheck->photo_path);
                    $order->precheck->update([
                        'photo_path'=>null,
                        'item_description'=>'[nach Kontolöschung entfernt]',
                        'item_size'=>null,
                        'item_type'=>null,
                    ]);
                }

                foreach($order->days as $day){
                    foreach($day->proofs as $proof){
                        if(!$proof->purged_at) Storage::disk('proofs')->delete($proof->storage_path);
                        $proof->update([
                            'original_name'=>'[nach Kontolöschung entfernt]',
                            'purged_at'=>$proof->purged_at ?: now(),
                        ]);
                    }
                }

                foreach($order->fieldValues as $field){
                    $field->update(['value'=>null]);
                }
            }

            foreach($user->conversations as $conversation){
                foreach($conversation->messages as $message){
                    if($message->attachment_path) Storage::disk('messages')->delete($message->attachment_path);

                    $updates=[
                        'attachment_path'=>null,
                        'attachment_original_name'=>null,
                        'attachment_mime'=>null,
                        'attachment_size'=>null,
                        'attachment_sha256'=>null,
                    ];

                    if($message->user_id===$user->id){
                        $updates['body']='[nach Kontolöschung entfernt]';
                    }

                    $message->update($updates);
                }
            }

            $user->profile?->update([
                'phone'=>null,
                'street'=>null,
                'postal_code'=>null,
                'city'=>null,
                'country_code'=>'DE',
            ]);

            $user->warnings()->delete();
            $user->restrictions()->delete();
            $user->userNotifications()->delete();

            $user->update([
                'first_name'=>'Gelöscht',
                'last_name'=>'Benutzer',
                'birth_date'=>'1970-01-01',
                'email'=>'deleted+'.$user->id.'+'.Str::lower(Str::random(16)).'@invalid.local',
                'password'=>Hash::make(Str::random(64)),
                'status'=>'deleted',
                'verified_at'=>null,
            ]);

            $user->forceFill([
                'email_verified_at'=>null,
                'remember_token'=>Str::random(60),
            ])->save();
        });
    }
}
