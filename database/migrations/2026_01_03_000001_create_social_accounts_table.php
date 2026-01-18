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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');           // google, github, meta, etc.
            $table->string('provider_id');        // Provider's user ID
            $table->string('provider_email')->nullable();
            $table->string('provider_avatar')->nullable();
            $table->string('provider_name')->nullable();
            $table->json('provider_data')->nullable(); // Raw provider response for future use
            $table->timestamps();

            // Prevent duplicate connections (same provider account can't be linked twice)
            $table->unique(['provider', 'provider_id']);
            // One provider per user (user can't have two Google accounts linked)
            $table->unique(['user_id', 'provider']);
            // Index for faster lookups
            $table->index(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
