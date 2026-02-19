<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Clean up old unique constraint on kian_submissions
     */
    public function up(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop the old single-column unique constraint
            // This is safe now that we have the new composite unique constraint
            DB::statement('ALTER TABLE kian_submissions DROP INDEX kian_submissions_tax_case_id_unique');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not drop old unique index', ['error' => $e->getMessage()]);
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
            // Recreate the old single-column unique constraint
            DB::statement('ALTER TABLE kian_submissions ADD UNIQUE INDEX kian_submissions_tax_case_id_unique (tax_case_id)');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not recreate old unique index', ['error' => $e->getMessage()]);
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
