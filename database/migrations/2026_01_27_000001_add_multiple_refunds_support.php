<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration enables multiple refunds per tax case by:
     * - Removing the unique constraint on tax_case_id
     * - Adding stage_source field to track which stage triggered the refund
     * - Adding sequence_number field to track refund order per case
     * - Adding triggered_by_decision_id to link back to decision that triggered the refund
     */
    public function up(): void
    {
        Schema::table('refund_processes', function (Blueprint $table) {
            // First, drop the foreign key constraint that depends on the unique index
            $table->dropForeign(['tax_case_id']);
            
            // Now drop the unique constraint on tax_case_id
            $table->dropUnique(['tax_case_id']);
            
            // Add new columns to track refund source and order
            $table->enum('stage_source', ['PRELIMINARY', 'SKP', 'OBJECTION', 'APPEAL', 'SUPREME_COURT'])
                ->default('SKP')
                ->comment('Which stage triggered this refund');
            
            $table->integer('sequence_number')
                ->default(1)
                ->comment('Auto-incrementing sequence per tax case (1, 2, 3, etc)');
            
            $table->unsignedBigInteger('triggered_by_decision_id')
                ->nullable()
                ->comment('ID of the decision record that triggered this refund (polymorphic)');
            
            $table->string('triggered_by_decision_type')
                ->nullable()
                ->comment('Type of decision model (SkpRecord, ObjectionDecision, etc)');
            
            // Re-add the foreign key constraint (now allows multiple refunds per tax case)
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            
            // Add composite index for efficient queries
            $table->index(['tax_case_id', 'sequence_number']);
            $table->index('stage_source');
            $table->index('sequence_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('refund_processes', function (Blueprint $table) {
            // Drop new indexes
            $table->dropIndex(['tax_case_id', 'sequence_number']);
            $table->dropIndex(['stage_source']);
            $table->dropIndex(['sequence_number']);
            
            // Drop the foreign key constraint
            $table->dropForeign(['tax_case_id']);
            
            // Drop new columns
            $table->dropColumn([
                'stage_source',
                'sequence_number',
                'triggered_by_decision_id',
                'triggered_by_decision_type',
            ]);
            
            // Re-add unique constraint on tax_case_id and the foreign key
            $table->unique('tax_case_id');
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
        });
    }
};
