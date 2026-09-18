<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if(Schema::hasColumn('users','verified_at')){
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('verified_at');
            });
        }
    }

    public function down(): void
    {
        if(!Schema::hasColumn('users','verified_at')){
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable();
            });
        }
    }
};
