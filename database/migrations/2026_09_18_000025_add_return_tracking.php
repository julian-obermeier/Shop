<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->string('tracking_number',180)->nullable()->after('shipping_cost_paid_at');
            $table->timestamp('returned_at')->nullable()->after('tracking_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropColumn(['tracking_number','returned_at']);
        });
    }
};
