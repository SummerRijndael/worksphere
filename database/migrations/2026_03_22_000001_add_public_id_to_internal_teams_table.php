<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('internal_teams', 'public_id')) {
            Schema::table('internal_teams', function (Blueprint $table) {
                $table->string('public_id', 26)->nullable()->unique()->after('id');
            });
        }

        // Back-fill existing rows
        DB::table('internal_teams')->orderBy('id')->each(function ($team) {
            DB::table('internal_teams')
                ->where('id', $team->id)
                ->update(['public_id' => (string) Str::ulid()]);
        });

        // Make non-nullable now that every row has one
        Schema::table('internal_teams', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('internal_teams', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
