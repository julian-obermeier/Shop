<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30)->default('provider')->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('street')->nullable(); $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable(); $table->string('country_code', 2)->default('DE');
            $table->string('phone')->nullable(); $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary(); $table->string('token'); $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary(); $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable(); $table->text('user_agent')->nullable();
            $table->longText('payload'); $table->integer('last_activity')->index();
        });
    }
    public function down(): void { Schema::dropIfExists('sessions'); Schema::dropIfExists('password_reset_tokens'); Schema::dropIfExists('user_profiles'); Schema::dropIfExists('users'); }
};
