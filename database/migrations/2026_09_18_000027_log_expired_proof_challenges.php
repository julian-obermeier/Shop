<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proof_challenges', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('proof_challenges', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });
    }
};
