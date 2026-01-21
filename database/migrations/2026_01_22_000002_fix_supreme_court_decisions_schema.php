<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix the supreme_court_decisions table schema:
     * - Remove legacy next_stage field
     * - Add missing notes field
     */
    public function up(): void
    {
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            // Remove legacy next_stage if it exists
            if (Schema::hasColumn('supreme_court_decisions', 'next_stage')) {
                $table->dropColumn('next_stage');
            }
            
            // Add notes field if it doesn't exist
            if (!Schema::hasColumn('supreme_court_decisions', 'notes')) {
                $table->text('notes')->nullable()->after('decision_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            // Restore for rollback
            if (!Schema::hasColumn('supreme_court_decisions', 'next_stage')) {
                $table->integer('next_stage')->default(12)->after('decision_notes');
            }
            
            if (Schema::hasColumn('supreme_court_decisions', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
