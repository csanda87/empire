<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained();
            $table->string('title');
            $table->string('type');
            $table->string('color');
            $table->integer('price');
            $table->integer('mortgage_price');
            $table->integer('unmortgage_price');
            $table->integer('rent')->nullable();
            $table->integer('rent_color_set')->nullable();
            $table->integer('rent_one_unit')->nullable();
            $table->integer('rent_two_unit')->nullable();
            $table->integer('rent_three_unit')->nullable();
            $table->integer('rent_four_unit')->nullable();
            $table->integer('rent_five_unit')->nullable();
            $table->integer('house_price')->nullable();
            // SQLite doesnt support setting default values for timestamps
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
