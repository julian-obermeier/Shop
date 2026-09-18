<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offer_waitlist_entries', function (Blueprint $table) {
            $table->unsignedInteger('reservation_remaining_seconds')->nullable()->after('reservation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('offer_waitlist_entries', function (Blueprint $table) {
            $table->dropColumn('reservation_remaining_seconds');
        });
    }
};
