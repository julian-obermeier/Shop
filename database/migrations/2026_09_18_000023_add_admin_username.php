<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username',80)->nullable()->unique()->after('role');
        });

        $used=[];

        DB::table('users')
            ->where('role','admin')
            ->orderBy('id')
            ->get(['id','email'])
            ->each(function($user) use (&$used){
                $local=Str::before((string)$user->email,'@');
                $base=Str::lower(preg_replace('/[^a-zA-Z0-9._-]+/','-',trim($local)) ?: 'admin');
                $base=trim($base,'-._') ?: 'admin';
                $candidate=substr($base,0,70);
                $suffix=2;

                while(in_array($candidate,$used,true) || DB::table('users')->where('username',$candidate)->exists()){
                    $candidate=substr($base,0,65).'-'.$suffix++;
                }

                DB::table('users')->where('id',$user->id)->update(['username'=>$candidate]);
                $used[]=$candidate;
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
