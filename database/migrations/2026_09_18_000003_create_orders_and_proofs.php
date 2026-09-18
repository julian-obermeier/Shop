<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('requested')->index();
            $table->decimal('compensation_total', 10, 2);
            $table->json('offer_snapshot');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('price_delta', 10, 2);
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('order_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->date('date');
            $table->unsignedTinyInteger('required_proofs')->default(0);
            $table->string('status', 30)->default('open');
            $table->timestamps();
            $table->unique(['order_id', 'day_number']);
        });

        Schema::create('proof_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('photo');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->string('review_status', 30)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_submissions');
        Schema::dropIfExists('order_days');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_options');
        Schema::dropIfExists('orders');
    }
};
