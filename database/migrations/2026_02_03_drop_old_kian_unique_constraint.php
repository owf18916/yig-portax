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
        try {
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'mysql') {
                // MySQL: Disable foreign key checks temporarily
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } else if ($driver === 'pgsql') {
                // PostgreSQL: Disable foreign key constraints
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }

            // Drop the old single-column unique constraint
            // This is safe now that we have the new composite unique constraint
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE kian_submissions DROP INDEX kian_submissions_tax_case_id_unique');
            } else if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE kian_submissions DROP CONSTRAINT IF EXISTS kian_submissions_tax_case_id_unique');
            }

            if ($driver === 'mysql') {
                // Re-enable foreign key checks
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not drop old unique index', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            
            if ($driver === 'mysql') {
                // MySQL: Disable foreign key checks temporarily
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } else if ($driver === 'pgsql') {
                // PostgreSQL: Disable foreign key constraints
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }

            // Recreate the old single-column unique constraint
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE kian_submissions ADD UNIQUE INDEX kian_submissions_tax_case_id_unique (tax_case_id)');
            } else if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE kian_submissions ADD CONSTRAINT kian_submissions_tax_case_id_unique UNIQUE (tax_case_id)');
            }

            if ($driver === 'mysql') {
                // Re-enable foreign key checks
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Could not recreate old unique index', ['error' => $e->getMessage()]);
        }
    }
};
