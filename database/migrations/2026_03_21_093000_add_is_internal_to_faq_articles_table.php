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
        Schema::table('faq_articles', function (Blueprint $table) {
            if (! Schema::hasColumn('faq_articles', 'is_internal')) {
                $table->boolean('is_internal')->default(false)->after('is_published')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faq_articles', function (Blueprint $table) {
            if (Schema::hasColumn('faq_articles', 'is_internal')) {
                $table->dropIndex(['is_internal']);
                $table->dropColumn('is_internal');
            }
        });
    }
};

