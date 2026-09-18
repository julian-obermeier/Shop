<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('status');
            $table->text('deactivation_reason')->nullable()->after('deactivated_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('paused_from_status',40)->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paused_from_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at','deactivation_reason']);
        });
    }
};
