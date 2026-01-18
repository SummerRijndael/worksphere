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
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('provider')->default('custom'); // custom, gmail, outlook
            $table->string('auth_type')->default('password'); // password, oauth

            // IMAP Settings
            $table->string('imap_host')->nullable();
            $table->integer('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl'); // ssl, tls, none

            // SMTP Settings
            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_encryption')->default('tls');

            // Password Auth (encrypted)
            $table->string('username')->nullable();
            $table->text('password')->nullable();

            // OAuth Tokens (encrypted)
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);

            // Sync status tracking
            $table->string('sync_status')->default('pending'); // pending, seeding, syncing, completed, failed
            $table->timestamp('initial_sync_completed_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedBigInteger('last_synced_uid')->nullable();
            $table->json('sync_cursor')->nullable();
            $table->text('sync_error')->nullable();

            // Re-Auth
            $table->boolean('needs_reauth')->default(false);
            $table->integer('consecutive_failures')->default(0);

            // Storage
            $table->bigInteger('storage_used')->nullable()->default(0);
            $table->bigInteger('storage_limit')->nullable();
            $table->timestamp('storage_updated_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['team_id', 'is_active']);
            $table->index('sync_status');
            $table->index('is_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
