<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL may use the existing composite UNIQUE index as the supporting
        // index for the order_id foreign key. Create a dedicated FK index first,
        // otherwise MySQL refuses to drop the UNIQUE index with error 1553.
        Schema::table('order_days', function (Blueprint $table) {
            $table->index('order_id','order_days_order_id_fk_index');
        });

        Schema::table('order_days', function (Blueprint $table) {
            $table->dropUnique(['order_id','day_number']);
            $table->unique(['order_id','series_number','day_number'],'order_days_order_series_day_unique');
        });

        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->string('window_key',80)->nullable()->after('type')->index();
            $table->text('text_value')->nullable()->after('window_key');
            $table->foreignId('proof_challenge_id')->nullable()->after('proof_code')->constrained('proof_challenges')->nullOnDelete();
            $table->string('rejection_kind',40)->nullable()->after('review_comment');
        });

        Schema::table('proof_challenges', function (Blueprint $table) {
            $table->string('window_key',80)->nullable()->after('purpose')->index();
        });

        Schema::table('wallet_accounts', function (Blueprint $table) {
            $table->decimal('available_balance_override',10,2)->nullable()->after('user_id');
            $table->timestamp('available_balance_override_at')->nullable()->after('available_balance_override');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('reliability_issue_count')->default(0)->after('series_interruptions');
            $table->text('last_reliability_issue')->nullable()->after('reliability_issue_count');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('review_status',30)->default('pending')->after('status')->index();
            $table->text('review_comment')->nullable()->after('review_status');
            $table->timestamp('resubmit_due_at')->nullable()->after('review_comment')->index();
        });

        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_active_orders')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->dropColumn('max_active_orders');
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['review_status','review_comment','resubmit_due_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reliability_issue_count','last_reliability_issue']);
        });
        Schema::table('wallet_accounts', function (Blueprint $table) {
            $table->dropColumn(['available_balance_override','available_balance_override_at']);
        });
        Schema::table('proof_challenges', function (Blueprint $table) {
            $table->dropColumn('window_key');
        });
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proof_challenge_id');
            $table->dropColumn(['window_key','text_value','rejection_kind']);
        });
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropUnique('order_days_order_series_day_unique');
            $table->unique(['order_id','day_number']);
        });

        Schema::table('order_days', function (Blueprint $table) {
            $table->dropIndex('order_days_order_id_fk_index');
        });
    }
};
