<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('color')->default('bg-blue-500');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        // Pivot table for email-label relationship
        Schema::create('email_email_label', function (Blueprint $table) {
            $table->foreignId('email_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_label_id')->constrained()->onDelete('cascade');
            $table->primary(['email_id', 'email_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_email_label');
        Schema::dropIfExists('email_labels');
    }
};
