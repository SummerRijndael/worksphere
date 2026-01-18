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
        Schema::create('team_roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('color')->default('primary');
            $table->unsignedTinyInteger('level')->default(50);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'is_default']);
            $table->index(['team_id', 'level']);
        });

        Schema::create('team_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_role_id')->constrained('team_roles')->cascadeOnDelete();
            $table->string('permission');
            $table->timestamps();

            $table->unique(['team_role_id', 'permission']);
            $table->index(['permission']);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->foreignId('team_role_id')->nullable()->after('role')->constrained('team_roles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_role_id');
        });

        Schema::dropIfExists('team_role_permissions');
        Schema::dropIfExists('team_roles');
    }
};
