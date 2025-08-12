<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('player_assets', function (Blueprint $table) {
            $table->unsignedTinyInteger('units')->default(0)->after('itemable_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_assets', function (Blueprint $table) {
            $table->dropColumn('units');
        });
    }
};


