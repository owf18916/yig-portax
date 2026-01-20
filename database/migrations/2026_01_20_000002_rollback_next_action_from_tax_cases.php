<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Rollback: Remove Next Action metadata fields from tax_cases table
     * These fields should be stored per-stage instead, not at tax case header level
     */
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropColumn(['next_action', 'next_action_due_date', 'status_comment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->text('next_action')->nullable()->after('description')->comment('Next action to take on this case');
            $table->date('next_action_due_date')->nullable()->after('next_action')->comment('Due date for the next action');
            $table->text('status_comment')->nullable()->after('next_action_due_date')->comment('Additional status comment');
        });
    }
};
