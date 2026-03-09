<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('address_to')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->dropForeign(['sent_by']);
            $table->dropColumn(['sent_by', 'address_to']);
        });
    }
};
