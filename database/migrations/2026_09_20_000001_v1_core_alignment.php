<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'parent_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('id')->index();
                $table->string('kind', 30)->default('physical')->after('icon')->index();
                $table->unsignedInteger('sort_order')->default(0)->after('kind');
                $table->boolean('system_template')->default(false)->after('sort_order');
                $table->json('config')->nullable()->after('system_template');
            });
        }

        if (!Schema::hasColumn('offers', 'lifecycle_status')) {
            Schema::table('offers', function (Blueprint $table) {
                $table->string('lifecycle_status', 30)->default('draft')->after('active')->index();
                $table->unsignedInteger('version_no')->default(1)->after('lifecycle_status');
                $table->string('fulfillment_type', 30)->default('days')->after('version_no')->index();
                $table->boolean('is_combination')->default(false)->after('fulfillment_type');
            });

            DB::table('offers')->where('active', true)->update(['lifecycle_status' => 'active']);
            DB::table('offers')->where('active', false)->update(['lifecycle_status' => 'draft']);
        }

        if (!Schema::hasColumn('orders', 'phase')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('phase', 30)->default('preparation')->after('status')->index();
                $table->timestamp('archived_at')->nullable()->after('completed_at')->index();
            });
        }

        if (!Schema::hasColumn('order_days', 'plan')) {
            Schema::table('order_days', function (Blueprint $table) {
                $table->json('plan')->nullable()->after('required_proofs');
                $table->string('source_type', 40)->default('regular')->after('plan')->index();
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            });
        }

        if (!Schema::hasTable('category_fields')) {
            Schema::create('category_fields', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->string('key', 100);
                $table->string('label');
                $table->string('type', 30)->default('text');
                $table->json('options')->nullable();
                $table->boolean('required')->default(false);
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['category_id', 'key']);
            });
        }

        if (!Schema::hasTable('offer_versions')) {
            Schema::create('offer_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->json('snapshot');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['offer_id', 'version']);
            });
        }

        if (!Schema::hasTable('order_components')) {
            Schema::create('order_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 30)->default('physical')->index();
                $table->string('title');
                $table->decimal('base_amount', 10, 2)->default(0);
                $table->decimal('options_amount', 10, 2)->default(0);
                $table->decimal('bonus_amount', 10, 2)->default(0);
                $table->decimal('adjustment_amount', 10, 2)->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->json('config')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_runs')) {
            Schema::create('order_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('run_number');
                $table->string('status', 30)->default('preparation')->index();
                $table->text('item_description')->nullable();
                $table->json('item_meta')->nullable();
                $table->text('restart_reason')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'run_number']);
            });
        }

        if (!Schema::hasTable('order_precheck_evidences')) {
            Schema::create('order_precheck_evidences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_precheck_id')->nullable()->constrained('order_prechecks')->cascadeOnDelete();
                $table->string('slot_key', 100);
                $table->string('label');
                $table->string('storage_path');
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');
                $table->string('sha256', 64);
                $table->string('status', 30)->default('submitted')->index();
                $table->string('rejection_kind', 50)->nullable();
                $table->text('admin_comment')->nullable();
                $table->timestamp('resubmit_due_at')->nullable()->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['order_id', 'slot_key']);
            });
        }

        if (!Schema::hasTable('violations')) {
            Schema::create('violations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_day_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 60)->index();
                $table->string('status', 30)->default('open')->index();
                $table->text('reason');
                $table->json('metadata')->nullable();
                $table->timestamp('detected_at')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('extension_days')) {
            Schema::create('extension_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('violation_id')->nullable()->constrained()->nullOnDelete();
                $table->string('source_type', 40)->index();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedInteger('sequence_no');
                $table->date('date')->nullable()->index();
                $table->boolean('paid')->default(false);
                $table->decimal('amount', 10, 2)->default(0);
                $table->text('reason')->nullable();
                $table->string('status', 30)->default('planned')->index();
                $table->timestamps();
                $table->unique(['order_id', 'sequence_no']);
            });
        }

        if (!Schema::hasTable('manual_extra_days')) {
            Schema::create('manual_extra_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('extension_day_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('paid')->default(false);
                $table->decimal('amount', 10, 2)->default(0);
                $table->text('reason')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('damage_cases')) {
            Schema::create('damage_cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_run_id')->nullable()->constrained('order_runs')->nullOnDelete();
                $table->string('status', 40)->default('reported')->index();
                $table->text('reason');
                $table->json('initial_evidence')->nullable();
                $table->text('decision_note')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('damage_evidence_requests')) {
            Schema::create('damage_evidence_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('damage_case_id')->constrained()->cascadeOnDelete();
                $table->string('type', 30);
                $table->text('instructions');
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamp('fulfilled_at')->nullable();
                $table->json('submission')->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('spontaneous_requests')) {
            Schema::create('spontaneous_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 30)->default('photo')->index();
                $table->unsignedInteger('required_count')->default(1);
                $table->text('instructions');
                $table->timestamp('due_at')->index();
                $table->timestamp('grace_due_at')->nullable()->index();
                $table->string('status', 30)->default('requested')->index();
                $table->json('submission')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_tasks')) {
            Schema::create('order_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->json('fields')->nullable();
                $table->json('schedule')->nullable();
                $table->decimal('additional_compensation', 10, 2)->default(0);
                $table->boolean('violation_on_failure')->default(true);
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_task_executions')) {
            Schema::create('order_task_executions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_task_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamp('grace_due_at')->nullable()->index();
                $table->json('submission')->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('digital_components')) {
            Schema::create('digital_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_component_id')->nullable()->constrained('order_components')->nullOnDelete();
                $table->string('title');
                $table->json('requirements');
                $table->decimal('base_amount', 10, 2)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('digital_versions')) {
            Schema::create('digital_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('digital_component_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version_no');
                $table->string('submission_type', 30);
                $table->longText('text_content')->nullable();
                $table->string('storage_path')->nullable();
                $table->string('playback_path')->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('sha256', 64)->nullable();
                $table->json('technical_meta')->nullable();
                $table->boolean('final_submission')->default(false);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->unique(['digital_component_id', 'version_no']);
            });
        }

        if (!Schema::hasTable('revision_rounds')) {
            Schema::create('revision_rounds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('digital_component_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('round_no');
                $table->string('status', 30)->default('open')->index();
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['digital_component_id', 'round_no']);
            });
        }

        if (!Schema::hasTable('revision_items')) {
            Schema::create('revision_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('revision_round_id')->constrained()->cascadeOnDelete();
                $table->string('priority', 20)->default('normal');
                $table->text('description');
                $table->string('reference_type', 30)->nullable();
                $table->string('reference_value')->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->text('admin_comment')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rights_acceptances')) {
            Schema::create('rights_acceptances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('version', 50);
                $table->string('terms_hash', 64);
                $table->timestamp('accepted_at');
                $table->timestamps();
                $table->unique(['order_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('order_shipping_steps')) {
            Schema::create('order_shipping_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('sort_order');
                $table->string('key', 100);
                $table->string('title');
                $table->text('instructions')->nullable();
                $table->json('requirements')->nullable();
                $table->timestamp('due_at')->nullable()->index();
                $table->string('status', 30)->default('locked')->index();
                $table->json('submission')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'key']);
            });
        }

        if (!Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('type', 60)->index();
                $table->string('title');
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable()->index();
                $table->string('status', 30)->default('open')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'calendar_events',
            'order_shipping_steps',
            'rights_acceptances',
            'revision_items',
            'revision_rounds',
            'digital_versions',
            'digital_components',
            'order_task_executions',
            'order_tasks',
            'spontaneous_requests',
            'damage_evidence_requests',
            'damage_cases',
            'manual_extra_days',
            'extension_days',
            'violations',
            'order_precheck_evidences',
            'order_runs',
            'order_components',
            'offer_versions',
            'category_fields',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('order_days', 'plan')) {
            Schema::table('order_days', function (Blueprint $table) {
                $table->dropColumn(['plan', 'source_type', 'source_id']);
            });
        }

        if (Schema::hasColumn('orders', 'phase')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['phase', 'archived_at']);
            });
        }

        if (Schema::hasColumn('offers', 'lifecycle_status')) {
            Schema::table('offers', function (Blueprint $table) {
                $table->dropColumn(['lifecycle_status', 'version_no', 'fulfillment_type', 'is_combination']);
            });
        }

        if (Schema::hasColumn('categories', 'parent_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn(['parent_id', 'kind', 'sort_order', 'system_template', 'config']);
            });
        }
    }
};
