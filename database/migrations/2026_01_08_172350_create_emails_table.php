<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('email_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('message_id')->nullable()->index(); // IMAP Message-ID
            $table->string('thread_id')->nullable()->index(); // For threading
            $table->string('folder')->default('inbox')->index();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to'); // [{email, name}]
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject', 998); // RFC 2822 limit
            $table->text('preview')->nullable(); // First ~200 chars
            $table->longText('body_html')->nullable();
            $table->longText('body_raw')->nullable()->comment('Original unsanitized HTML for debugging/legal');
            $table->longText('body_plain')->nullable();
            $table->json('headers')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->unsignedBigInteger('imap_uid')->nullable()->index(); // IMAP UID for sync
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sanitized_at')->nullable()->comment('Timestamp when email was sanitized');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'folder']);
            $table->index(['user_id', 'is_read']);
            $table->index(['email_account_id', 'imap_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
