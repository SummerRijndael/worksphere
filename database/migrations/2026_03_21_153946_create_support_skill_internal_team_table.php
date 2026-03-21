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
        Schema::create('support_skill_internal_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internal_team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['support_skill_id', 'internal_team_id'], 'skill_team_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_skill_internal_team');
    }
};
