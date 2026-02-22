<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration refactors refund_processes to use stage_id (integer) instead of 
     * stage_source (string enum), making it consistent with KIAN pattern.
     * 
     * Changes:
     * - Replace `stage_source` (string) with `stage_id` (integer, 0 for PRELIMINARY)
     * - Add mapping function for historical data
     * - Maintain all other columns intact
     * - Update indexes for efficiency
     */
    public function up(): void
    {
        Schema::table('refund_processes', function (Blueprint $table) {
            // Drop old indexes that depend on stage_source
            if (Schema::hasIndex('refund_processes', 'refund_processes_stage_source_index')) {
                $table->dropIndex(['stage_source']);
            }

            // Add stage_id column only if it doesn't already exist
            if (!Schema::hasColumn('refund_processes', 'stage_id')) {
                $table->unsignedInteger('stage_id')
                    ->nullable()
                    ->after('tax_case_id')
                    ->comment('Stage that triggered refund (0=PRELIMINARY, 4,7,10,12)');
            }

            // Temporarily keep stage_source for data migration
            // Will be dropped in a separate cleanup migration after data is migrated
        });

        // Migrate existing data: map stage_source string to stage_id integer
        // Only update rows where stage_id is NULL
        DB::statement("
            UPDATE refund_processes SET stage_id = CASE
                WHEN stage_source = 'PRELIMINARY' THEN 0
                WHEN stage_source = 'SKP' THEN 4
                WHEN stage_source = 'OBJECTION' THEN 7
                WHEN stage_source = 'APPEAL' THEN 10
                WHEN stage_source = 'SUPREME_COURT' THEN 12
                ELSE NULL
            END
            WHERE stage_id IS NULL
        ");

        // Make stage_id NOT NULL after migration is complete
        Schema::table('refund_processes', function (Blueprint $table) {
            // Only change if column exists
            if (Schema::hasColumn('refund_processes', 'stage_id')) {
                try {
                    $table->unsignedInteger('stage_id')->nullable(false)->change();
                } catch (\Exception $e) {
                    // Column might already be NOT NULL, which is fine
                    Log::warning('Could not change stage_id to NOT NULL: ' . $e->getMessage());
                }
            }
        });

        // Add new composite unique index (tax_case_id + stage_id) for enforcing one refund per stage
        Schema::table('refund_processes', function (Blueprint $table) {
            if (!Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_stage_id_unique')) {
                $table->unique(['tax_case_id', 'stage_id']);
            }
            
            // Check if stage_id index exists before adding
            if (!Schema::hasIndex('refund_processes', 'refund_processes_stage_id_index')) {
                $table->index('stage_id');
            }
            
            // Check if tax_case_id + sequence_number index exists before adding
            if (!Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_sequence_number_index')) {
                $table->index(['tax_case_id', 'sequence_number']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('refund_processes', function (Blueprint $table) {
            // Drop new indexes
            if (Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_stage_id_unique')) {
                $table->dropUnique(['tax_case_id', 'stage_id']);
            }
            
            if (Schema::hasIndex('refund_processes', 'refund_processes_stage_id_index')) {
                $table->dropIndex(['stage_id']);
            }

            // Drop stage_id column
            $table->dropColumn('stage_id');

            // Re-add old index on stage_source if it's still there
            if (Schema::hasColumn('refund_processes', 'stage_source')) {
                $table->index('stage_source');
            }
        });
    }
};
