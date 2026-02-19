<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates kian_submissions unique constraint to support multiple KIAN records per tax case
     * From unique(tax_case_id) to unique(tax_case_id, stage_id)
     * 
     * Note: stage_id column already exists in kian_submissions table
     */
    public function up(): void
    {
        // Disable foreign key checks temporarily to manipulate constraints
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // First, drop the old unique constraint using ALTER TABLE with DROP INDEX
            DB::statement('ALTER TABLE kian_submissions DROP INDEX kian_submissions_tax_case_id_unique');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not drop unique index', ['error' => $e->getMessage()]);
        }

        // Create a new unique index on (tax_case_id, stage_id) combination
        // Also need to ensure it can be used as backing index for foreign key
        try {
            DB::statement('ALTER TABLE kian_submissions ADD UNIQUE INDEX kian_submissions_tax_case_stage_unique (tax_case_id, stage_id)');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not create new unique index', ['error' => $e->getMessage()]);
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop the new composite unique constraint
            DB::statement('ALTER TABLE kian_submissions DROP INDEX kian_submissions_tax_case_stage_unique');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not drop unique index', ['error' => $e->getMessage()]);
        }

        // Restore the old unique constraint on tax_case_id only
        try {
            DB::statement('ALTER TABLE kian_submissions ADD UNIQUE INDEX kian_submissions_tax_case_id_unique (tax_case_id)');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not recreate unique index', ['error' => $e->getMessage()]);
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
