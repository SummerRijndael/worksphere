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
        Schema::table('events', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->after('id');
        });

        // Backfill existing events
        DB::table('events')->orderBy('id')->chunk(100, function ($events) {
            foreach ($events as $event) {
                if (empty($event->public_id)) {
                    DB::table('events')
                        ->where('id', $event->id)
                        ->update(['public_id' => (string) \Illuminate\Support\Str::ulid()]);
                }
            }
        });

        // Enforce not null and unique
        Schema::table('events', function (Blueprint $table) {
            $table->ulid('public_id')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
