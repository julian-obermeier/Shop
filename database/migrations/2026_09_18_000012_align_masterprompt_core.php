<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Bestehende Admin-/Mitarbeiterrollen auf das verbindliche Ein-Admin-Modell migrieren.
        $adminIds=DB::table('users')
            ->whereIn('role',['admin','superadmin','staff','accounting'])
            ->orderBy('id')
            ->pluck('id');

        if($adminIds->isNotEmpty()){
            $primary=(int)$adminIds->first();
            DB::table('users')->where('id',$primary)->update(['role'=>'admin']);
            if($adminIds->count()>1){
                DB::table('users')->whereIn('id',$adminIds->slice(1)->all())->update([
                    'role'=>'legacy_disabled',
                    'status'=>'suspended',
                ]);
            }
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('bank_iban',34)->nullable()->after('phone');
            $table->string('bank_account_holder')->nullable()->after('bank_iban');
            $table->string('paypal_email')->nullable()->after('bank_account_holder');
            $table->string('paypal_name')->nullable()->after('paypal_email');
            $table->timestamp('payout_details_changed_at')->nullable()->after('paypal_name');
            $table->timestamp('payout_name_approved_at')->nullable()->after('payout_details_changed_at');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('is_sock_wearing')->default(false)->after('requires_precheck')->index();
            $table->string('tracking_mode',20)->default('optional')->after('shipping_deadline_hours');
            $table->json('proof_requirements')->nullable()->after('rules');
            $table->json('inspection_config')->nullable()->after('proof_requirements');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->date('proposed_start_date')->nullable()->after('offer_snapshot');
            $table->date('confirmed_start_date')->nullable()->after('proposed_start_date')->index();
            $table->date('activation_date')->nullable()->after('confirmed_start_date');
            $table->unsignedSmallInteger('series_number')->default(1)->after('end_date');
            $table->unsignedTinyInteger('series_interruptions')->default(0)->after('series_number');
            $table->timestamp('paused_at')->nullable()->after('shipping_due_at');
            $table->json('current_requirements')->nullable()->after('paused_at');
            $table->timestamp('requirements_effective_at')->nullable()->after('current_requirements');
            $table->decimal('final_compensation',10,2)->nullable()->after('compensation_total');
        });

        Schema::table('order_days', function (Blueprint $table) {
            $table->unsignedSmallInteger('series_number')->default(1)->after('day_number')->index();
            $table->boolean('counts_toward_series')->default(true)->after('status');
            $table->text('invalid_reason')->nullable()->after('counts_toward_series');
        });

        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->string('proof_code',32)->nullable()->after('type');
            $table->timestamp('proof_code_expires_at')->nullable()->after('proof_code');
            $table->unsignedTinyInteger('retry_number')->default(0)->after('review_status');
            $table->timestamp('resubmit_due_at')->nullable()->after('retry_number')->index();
            $table->boolean('extra_retry_granted')->default(false)->after('resubmit_due_at');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('package_photo_path')->nullable()->after('tracking_number');
            $table->string('package_photo_sha256',64)->nullable()->after('package_photo_path');
            $table->string('receipt_photo_path')->nullable()->after('package_photo_sha256');
            $table->string('receipt_photo_sha256',64)->nullable()->after('receipt_photo_path');
        });

        Schema::table('payout_requests', function (Blueprint $table) {
            $table->date('processing_date')->nullable()->after('destination')->index();
            $table->timestamp('approved_at')->nullable()->after('admin_note');
            $table->timestamp('payment_executed_at')->nullable()->after('approved_at');
            $table->timestamp('completed_at')->nullable()->after('payment_executed_at');
            $table->timestamp('reopened_at')->nullable()->after('completed_at');
            $table->text('rejection_reason')->nullable()->after('reopened_at');
        });

        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->unsignedTinyInteger('required_successes')->default(5)->after('active');
            $table->unsignedTinyInteger('successful_count')->default(0)->after('required_successes');
            $table->timestamp('progress_reset_at')->nullable()->after('successful_count');
        });

        Schema::create('offer_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status',30)->default('waiting')->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->date('planned_start_date')->nullable();
            $table->timestamps();
            $table->unique(['offer_id','user_id']);
            $table->index(['offer_id','status','created_at']);
        });

        Schema::create('goods_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('categories');
            $table->decimal('base_percentage',5,2)->default(100);
            $table->json('extra_results')->nullable();
            $table->decimal('calculated_compensation',10,2)->default(0);
            $table->string('result',30)->default('pending')->index();
            $table->text('reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status',30)->default('requested')->index();
            $table->timestamp('requested_at');
            $table->timestamp('fulfillment_due_at')->index();
            $table->string('method',30)->nullable();
            $table->string('return_label_path')->nullable();
            $table->decimal('requested_shipping_cost',10,2)->nullable();
            $table->timestamp('shipping_cost_paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('proof_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_day_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose',40)->default('daily');
            $table->string('code',16);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_challenges');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('goods_inspections');
        Schema::dropIfExists('offer_waitlist_entries');

        Schema::table('user_restrictions', function (Blueprint $table) {
            $table->dropColumn(['required_successes','successful_count','progress_reset_at']);
        });
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropColumn(['processing_date','approved_at','payment_executed_at','completed_at','reopened_at','rejection_reason']);
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['package_photo_path','package_photo_sha256','receipt_photo_path','receipt_photo_sha256']);
        });
        Schema::table('proof_submissions', function (Blueprint $table) {
            $table->dropColumn(['proof_code','proof_code_expires_at','retry_number','resubmit_due_at','extra_retry_granted']);
        });
        Schema::table('order_days', function (Blueprint $table) {
            $table->dropColumn(['series_number','counts_toward_series','invalid_reason']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'proposed_start_date','confirmed_start_date','activation_date','series_number',
                'series_interruptions','paused_at','current_requirements','requirements_effective_at','final_compensation'
            ]);
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['is_sock_wearing','tracking_mode','proof_requirements','inspection_config']);
        });
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'bank_iban','bank_account_holder','paypal_email','paypal_name',
                'payout_details_changed_at','payout_name_approved_at'
            ]);
        });
    }
};
