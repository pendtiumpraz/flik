<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('movies', function (Blueprint $table) {
            // Cinematic hero/slider image — recommended 1920x800 (~2.4:1 ratio)
            // Distinct from backdrop_path (which is generic 16:9 background blur)
            $table->string('slider_path')->nullable()->after('backdrop_path');
        });
    }

    public function down()
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn('slider_path');
        });
    }
};
