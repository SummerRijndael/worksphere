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
        Schema::create('role_two_factor_enforcement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->json('allowed_methods');
            $table->boolean('is_active')->default(true);
            $table->foreignId('enforced_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('enforced_at')->useCurrent();
            $table->timestamps();

            $table->unique('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_two_factor_enforcement');
    }
};
