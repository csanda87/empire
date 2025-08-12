<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot drop FKs in-place; rebuild the table with correct FKs
            Schema::create('transaction_items_tmp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained();
                $table->string('type');
                $table->unsignedBigInteger('item_id')->nullable();
                $table->integer('amount')->nullable();
                $table->unsignedBigInteger('from_player_id')->nullable();
                $table->unsignedBigInteger('to_player_id')->nullable();
                // Note: keep existing columns; add correct FKs to players
                $table->foreign('from_player_id')->references('id')->on('players')->nullOnDelete();
                $table->foreign('to_player_id')->references('id')->on('players')->nullOnDelete();
            });

            // Copy existing data
            DB::statement('INSERT INTO transaction_items_tmp (id, transaction_id, type, item_id, amount, from_player_id, to_player_id)
                           SELECT id, transaction_id, type, item_id, amount, from_player_id, to_player_id FROM transaction_items');

            // Replace old table
            Schema::drop('transaction_items');
            Schema::rename('transaction_items_tmp', 'transaction_items');
        } else {
            // MySQL/Postgres path: drop bad FKs and add correct ones
            Schema::table('transaction_items', function (Blueprint $table) {
                try { $table->dropForeign(['from_player_id']); } catch (\Throwable $e) {}
                try { $table->dropForeign(['to_player_id']); } catch (\Throwable $e) {}
            });

            Schema::table('transaction_items', function (Blueprint $table) {
                $table->foreign('from_player_id')
                    ->references('id')->on('players')
                    ->nullOnDelete();
                $table->foreign('to_player_id')
                    ->references('id')->on('players')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // Rebuild back with original (incorrect) FKs to games to reverse the change
            Schema::create('transaction_items_tmp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained();
                $table->string('type');
                $table->unsignedBigInteger('item_id')->nullable();
                $table->integer('amount')->nullable();
                $table->unsignedBigInteger('from_player_id')->nullable();
                $table->unsignedBigInteger('to_player_id')->nullable();
                $table->foreign('from_player_id')->references('id')->on('games')->nullOnDelete();
                $table->foreign('to_player_id')->references('id')->on('games')->nullOnDelete();
            });
            DB::statement('INSERT INTO transaction_items_tmp (id, transaction_id, type, item_id, amount, from_player_id, to_player_id)
                           SELECT id, transaction_id, type, item_id, amount, from_player_id, to_player_id FROM transaction_items');
            Schema::drop('transaction_items');
            Schema::rename('transaction_items_tmp', 'transaction_items');
        } else {
            Schema::table('transaction_items', function (Blueprint $table) {
                try { $table->dropForeign(['from_player_id']); } catch (\Throwable $e) {}
                try { $table->dropForeign(['to_player_id']); } catch (\Throwable $e) {}
            });
            Schema::table('transaction_items', function (Blueprint $table) {
                $table->foreign('from_player_id')->references('id')->on('games')->nullOnDelete();
                $table->foreign('to_player_id')->references('id')->on('games')->nullOnDelete();
            });
        }
    }
};


