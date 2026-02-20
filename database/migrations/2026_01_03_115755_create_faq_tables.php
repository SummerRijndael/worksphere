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
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faq_articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('category_id')->constrained('faq_categories')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->json('tags')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('views')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
            $table->foreignId('author_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faq_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('faq_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable(); // For guests
            $table->text('content');
            $table->string('ip_address')->nullable();
            $table->boolean('is_approved')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('faq_article_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_article_id')->constrained('faq_articles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users'); // Author of the snapshot
            $table->string('title');
            $table->longText('content');
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['faq_article_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faq_article_versions');
        Schema::dropIfExists('faq_comments');
        Schema::dropIfExists('faq_articles');
        Schema::dropIfExists('faq_categories');
    }
};
