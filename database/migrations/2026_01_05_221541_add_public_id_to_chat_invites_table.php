<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add nullable ULID column first
        Schema::table('chat_invites', function (Blueprint $table) {
            $table->ulid('public_id')->after('id')->nullable();
        });

        // Backfill existing records with ULIDs
        DB::table('chat_invites')->whereNull('public_id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('chat_invites')
                    ->where('id', $row->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            }
        });

        // Make column non-nullable and unique
        Schema::table('chat_invites', function (Blueprint $table) {
            $table->ulid('public_id')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_invites', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
