<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->json('proof_data')->nullable()->after('text_value');
        });
    }

    public function down(): void
    {
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->dropColumn('proof_data');
        });
    }
};
