<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $hasIndex = collect(DB::select('SHOW INDEX FROM media'))
            ->contains(fn ($index) => ($index->Key_name ?? null) === 'media_model_type_model_id_index');

        if ($hasIndex) {
            DB::statement('ALTER TABLE media DROP INDEX media_model_type_model_id_index');
        }

        // Support polymorphic owners with UUID keys (e.g. meeting_recordings.id).
        DB::statement('ALTER TABLE media MODIFY model_id VARCHAR(191) NOT NULL');
        DB::statement('ALTER TABLE media ADD INDEX media_model_type_model_id_index (model_type, model_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Best-effort rollback: only convert back if all current values are numeric.
        $nonNumericCount = (int) DB::table('media')
            ->whereRaw("model_id NOT REGEXP '^[0-9]+$'")
            ->count();

        if ($nonNumericCount > 0) {
            return;
        }

        $hasIndex = collect(DB::select('SHOW INDEX FROM media'))
            ->contains(fn ($index) => ($index->Key_name ?? null) === 'media_model_type_model_id_index');

        if ($hasIndex) {
            DB::statement('ALTER TABLE media DROP INDEX media_model_type_model_id_index');
        }

        DB::statement('ALTER TABLE media MODIFY model_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE media ADD INDEX media_model_type_model_id_index (model_type, model_id)');
    }
};
