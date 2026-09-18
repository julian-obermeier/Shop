<?php

namespace App\Console\Commands;

use App\Models\IdentityVerification;
use App\Models\LoginChallenge;
use App\Models\Message;
use App\Models\OrderPrecheck;
use App\Models\ProofSubmission;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredPrivateFiles extends Command
{
    protected $signature='privacy:purge';
    protected $description='Purge expired private files according to configured retention settings';

    public function handle(): int
    {
        $counts=[
            'identity'=>0,
            'prechecks'=>0,
            'proofs'=>0,
            'messages'=>0,
            'challenges'=>0,
            'password_tokens'=>0,
        ];

        $identityDays=max(1,(int)Setting::valueOf('identity_retention_days',30));
        $precheckDays=max(1,(int)Setting::valueOf('precheck_retention_days',180));
        $proofDays=max(1,(int)Setting::valueOf('proof_retention_days',365));
        $messageDays=max(1,(int)Setting::valueOf('message_attachment_retention_days',365));

        IdentityVerification::query()
            ->whereIn('status',['accepted','rejected'])
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at','<=',now()->subDays($identityDays))
            ->where(fn($q)=>$q->whereNotNull('document_front_path')->orWhereNotNull('document_back_path'))
            ->chunkById(100,function($rows) use(&$counts){
                foreach($rows as $verification){
                    foreach([$verification->document_front_path,$verification->document_back_path] as $path){
                        if($path) Storage::disk('identity')->delete($path);
                    }
                    $verification->update([
                        'document_front_path'=>null,
                        'document_back_path'=>null,
                    ]);
                    $counts['identity']++;
                }
            });

        OrderPrecheck::query()
            ->whereNotNull('photo_path')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at','<=',now()->subDays($precheckDays))
            ->whereHas('order',fn($q)=>$q->whereIn('status',[
                'compensation_released','completed','cancelled','rejected',
            ]))
            ->chunkById(100,function($rows) use(&$counts){
                foreach($rows as $precheck){
                    Storage::disk('prechecks')->delete($precheck->photo_path);
                    $precheck->update(['photo_path'=>null]);
                    $counts['prechecks']++;
                }
            });

        ProofSubmission::query()
            ->whereNull('purged_at')
            ->where('created_at','<=',now()->subDays($proofDays))
            ->whereHas('orderDay.order',fn($q)=>$q->whereIn('status',[
                'compensation_released','completed','cancelled','rejected',
            ]))
            ->chunkById(100,function($rows) use(&$counts){
                foreach($rows as $proof){
                    Storage::disk('proofs')->delete($proof->storage_path);
                    $proof->update(['purged_at'=>now()]);
                    $counts['proofs']++;
                }
            });

        Message::query()
            ->whereNotNull('attachment_path')
            ->where('created_at','<=',now()->subDays($messageDays))
            ->whereHas('conversation',fn($q)=>$q->where('status','closed'))
            ->chunkById(100,function($rows) use(&$counts){
                foreach($rows as $message){
                    Storage::disk('messages')->delete($message->attachment_path);
                    $message->update([
                        'attachment_path'=>null,
                        'attachment_original_name'=>null,
                        'attachment_mime'=>null,
                        'attachment_size'=>null,
                        'attachment_sha256'=>null,
                    ]);
                    $counts['messages']++;
                }
            });

        $counts['challenges']=LoginChallenge::where(function($q){
            $q->where('expires_at','<',now()->subDay())
              ->orWhere('used_at','<',now()->subDay());
        })->delete();

        if(Schema::hasTable('password_reset_tokens')){
            $counts['password_tokens']=DB::table('password_reset_tokens')
                ->where('created_at','<',now()->subDay())
                ->delete();
        }

        foreach($counts as $name=>$count){
            $this->line($name.': '.$count);
        }

        return self::SUCCESS;
    }
}
