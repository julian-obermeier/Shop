<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('offer_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('key');
            $table->string('type', 30)->default('text');
            $table->text('help_text')->nullable();
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['offer_id','key']);
        });

        Schema::create('order_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('key');
            $table->longText('value')->nullable();
            $table->json('field_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['order_id','key']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('shipping_due_at')->nullable()->after('completed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_due_at');
        });
        Schema::dropIfExists('order_field_values');
        Schema::dropIfExists('offer_fields');
    }
};
