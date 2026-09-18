<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->decimal('base_compensation', 10, 2);
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedSmallInteger('minimum_minutes_per_day')->default(0);
            $table->unsignedTinyInteger('proofs_per_day')->default(0);
            $table->unsignedSmallInteger('shipping_deadline_hours')->default(24);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('requires_precheck')->default(false);
            $table->boolean('active')->default(false)->index();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->json('rules')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });

        Schema::create('offer_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->string('type', 30)->default('checkbox');
            $table->boolean('required')->default(false);
            $table->unsignedTinyInteger('extra_proofs_per_day')->default(0);
            $table->unsignedSmallInteger('extra_duration_days')->default(0);
            $table->json('rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_options');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('categories');
    }
};
