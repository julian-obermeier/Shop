<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('ownership_transferred_at')->nullable()->after('shipped_at');
            $table->timestamp('risk_transferred_at')->nullable()->after('ownership_transferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['ownership_transferred_at','risk_transferred_at']);
        });
    }
};
