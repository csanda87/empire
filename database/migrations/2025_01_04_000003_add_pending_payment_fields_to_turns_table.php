<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->unsignedInteger('pending_payment_amount')->nullable()->after('status');
            $table->unsignedBigInteger('pending_payment_to_player_id')->nullable()->after('pending_payment_amount');
            $table->string('pending_payment_reason')->nullable()->after('pending_payment_to_player_id');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'))->change();
        });
    }

    public function down(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->dropColumn('pending_payment_reason');
            $table->dropColumn('pending_payment_to_player_id');
            $table->dropColumn('pending_payment_amount');
        });
    }
};


