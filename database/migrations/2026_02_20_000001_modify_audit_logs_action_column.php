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
        Schema::table('audit_logs', function (Blueprint $table) {
            // Change enum to string to allow custom actions like 'KIAN_REMINDER_SENT'
            $table->string('action')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Revert back to enum
            $table->enum('action', ['created', 'updated', 'deleted', 'approved', 'submitted', 'rejected'])->default('updated')->change();
        });
    }
};
