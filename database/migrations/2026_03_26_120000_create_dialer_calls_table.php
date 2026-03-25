<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_calls', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->index();
            $table->string('provider_call_id')->nullable()->index();
            $table->string('direction', 20)->default('outbound');
            $table->string('from_number', 32)->nullable();
            $table->string('to_number', 32);
            $table->string('status', 32)->index();
            $table->string('contact_name')->nullable();
            $table->text('notes')->nullable();
            $table->json('acd_context')->nullable();
            $table->json('provider_payload')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_calls');
    }
};
