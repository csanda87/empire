<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->boolean('in_joint')->default(false)->after('is_bankrupt');
            $table->unsignedTinyInteger('joint_attempts')->default(0)->after('in_joint');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['in_joint', 'joint_attempts']);
        });
    }
};


