<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unsignedInteger('reliability_cycle_violations')->default(0)->after('payout_name_approved_at');
        });

        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->json('blocked_offer_ids')->nullable()->after('max_active_orders');
            $table->string('source_rule_key',80)->nullable()->after('blocked_offer_ids');
        });

        Schema::create('reliability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restriction_id')->nullable()->constrained('user_restrictions')->nullOnDelete();
            $table->string('type',80)->index();
            $table->string('event_kind',30)->default('violation')->index();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('unassigned_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_name')->nullable();
            $table->string('sender_address')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->date('shipping_date')->nullable();
            $table->timestamp('received_at');
            $table->text('matching_attempt')->nullable();
            $table->text('notes')->nullable();
            $table->string('status',30)->default('unassigned')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unassigned_shipments');
        Schema::dropIfExists('reliability_events');

        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
            $table->dropColumn(['blocked_offer_ids','source_rule_key']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('reliability_cycle_violations');
        });
    }
};
