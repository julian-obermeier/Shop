<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeExpiredPrivateFiles extends Command
{
    protected $signature='privacy:purge';
    protected $description='Purge only short-lived security tokens; order-related files are retained permanently';

    public function handle(): int
    {
        $passwordTokens=0;

        if(Schema::hasTable('password_reset_tokens')){
            $passwordTokens=DB::table('password_reset_tokens')
                ->where('created_at','<',now()->subDay())
                ->delete();
        }

        $this->line('password_tokens: '.$passwordTokens);
        $this->line('Auftragsbezogene Bilder, Nachweise und Nachrichtenanhänge werden gemäß MASTERPROMPT nicht automatisch gelöscht.');

        return self::SUCCESS;
    }
}
