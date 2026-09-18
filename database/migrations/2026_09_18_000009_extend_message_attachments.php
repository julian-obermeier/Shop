<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_original_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime',100)->nullable()->after('attachment_original_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
            $table->string('attachment_sha256',64)->nullable()->after('attachment_size');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_original_name',
                'attachment_mime',
                'attachment_size',
                'attachment_sha256',
            ]);
        });
    }
};
