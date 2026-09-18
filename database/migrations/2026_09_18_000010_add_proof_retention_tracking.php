<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('reviewed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
