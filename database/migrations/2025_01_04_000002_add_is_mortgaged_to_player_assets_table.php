<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_assets', function (Blueprint $table) {
            $table->boolean('is_mortgaged')->default(false)->after('units');
        });
    }

    public function down(): void
    {
        Schema::table('player_assets', function (Blueprint $table) {
            $table->dropColumn('is_mortgaged');
        });
    }
};


