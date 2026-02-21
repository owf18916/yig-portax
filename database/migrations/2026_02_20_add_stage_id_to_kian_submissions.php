<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add stage_id column to support multiple KIAN per case (one per stage: 4, 7, 10, 12)
     */
    public function up(): void
    {
        Schema::table('kian_submissions', function (Blueprint $table) {
            // Add stage_id column after tax_case_id if it doesn't exist
            if (!Schema::hasColumn('kian_submissions', 'stage_id')) {
                $table->tinyInteger('stage_id')->unsigned()->nullable()->after('tax_case_id');
            }
            
            // Add loss_amount column for storing calculated loss at each stage
            if (!Schema::hasColumn('kian_submissions', 'loss_amount')) {
                $table->decimal('loss_amount', 20, 2)->nullable()->after('stage_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kian_submissions', function (Blueprint $table) {
            $table->dropColumn('stage_id');
            $table->dropColumn('loss_amount');
        });
    }
};
