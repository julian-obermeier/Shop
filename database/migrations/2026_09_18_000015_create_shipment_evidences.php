<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipment_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type',30);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type',100);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256',64);
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->timestamps();
            $table->index(['shipment_id','type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_evidences');
    }
};
