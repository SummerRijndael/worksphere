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

    public function down(): void
    {
        Schema::dropIfExists('faq_article_versions');
    }
};
