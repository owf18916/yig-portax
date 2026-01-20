<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add Next Action metadata fields to tax_cases table
     * This is for Stage 1 (SPT Filing) which is stored directly in tax_cases, not in a separate table
     */
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('tax_cases', 'next_action')) {
                $table->text('next_action')->nullable()->after('description')->comment('Next action to take on this case');
            }
            if (!Schema::hasColumn('tax_cases', 'next_action_due_date')) {
                $table->date('next_action_due_date')->nullable()->after('next_action')->comment('Due date for the next action');
            }
            if (!Schema::hasColumn('tax_cases', 'status_comment')) {
                $table->text('status_comment')->nullable()->after('next_action_due_date')->comment('Additional status comment');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropColumn(['next_action', 'next_action_due_date', 'status_comment']);
        });
    }
};
