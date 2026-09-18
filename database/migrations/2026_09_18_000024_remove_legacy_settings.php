<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->whereIn('key',[
            'minimum_payout',
            'proof_reminders_enabled',
            'email_notifications_enabled',
            'identity_retention_days',
            'precheck_retention_days',
            'proof_retention_days',
            'message_attachment_retention_days',
        ])->delete();
    }

    public function down(): void
    {
        // Legacy settings are intentionally not recreated.
    }
};
