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
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained();
            $table->string('type');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->integer('amount')->nullable();
            $table->unsignedBigInteger('from_player_id')->nullable();
            $table->unsignedBigInteger('to_player_id')->nullable();

            // Correct player FKs
            $table->foreign('from_player_id')->references('id')->on('players');
            $table->foreign('to_player_id')->references('id')->on('players');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
