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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('username', 50)->unique();
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_password_set')->default(false);
            $table->timestamp('password_last_updated_at')->nullable();
            $table->rememberToken();

            // Profile Fields
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->json('skills')->nullable();
            $table->boolean('is_public')->default(false)->index();

            // Status & Preferences
            $table->string('status')->default('active')->index();
            $table->string('status_reason')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->json('preferences')->nullable();
            $table->string('presence_preference', 20)->default('online');
            $table->timestamp('last_seen_at')->nullable();

            // Social Auth
            $table->string('provider')->nullable()->index();
            $table->string('provider_id')->nullable();

            // Two Factor Auth
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_sms_enabled')->default(false);
            $table->timestamp('two_factor_sms_confirmed_at')->nullable();
            $table->boolean('two_factor_email_enabled')->default(false);

            // Two Factor Enforcement
            $table->boolean('two_factor_enforced')->default(false);
            $table->json('two_factor_allowed_methods')->nullable();
            $table->foreignId('two_factor_enforced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('two_factor_enforced_at')->nullable();

            // Login Tracking
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
