<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('faq_categories', function (Blueprint $table) {
            $table->uuid('public_id')->after('id')->unique()->nullable();
        });

        Schema::table('faq_articles', function (Blueprint $table) {
            $table->uuid('public_id')->after('id')->unique()->nullable();
        });

        // Populate existing records with UUIDs
        DB::table('faq_categories')->whereNull('public_id')->orderBy('id')->each(function ($cat) {
            DB::table('faq_categories')->where('id', $cat->id)->update(['public_id' => (string) Str::uuid()]);
        });

        DB::table('faq_articles')->whereNull('public_id')->orderBy('id')->each(function ($art) {
            DB::table('faq_articles')->where('id', $art->id)->update(['public_id' => (string) Str::uuid()]);
        });

        // Make not nullable
        Schema::table('faq_categories', function (Blueprint $table) {
            $table->uuid('public_id')->nullable(false)->change();
        });
        Schema::table('faq_articles', function (Blueprint $table) {
            $table->uuid('public_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('faq_categories', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });

        Schema::table('faq_articles', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
