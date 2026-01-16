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
        Schema::table('revisions', function (Blueprint $table) {
            // Add columns for the new revision flow
            $table->json('proposed_values')->nullable()->after('revised_data')->comment('User proposed values for revision');
            $table->json('proposed_document_changes')->nullable()->after('proposed_values')->comment('Files to delete (ids) and new files (ids)');
            $table->text('rejection_reason')->nullable()->change();
            
            // Update enum to include new statuses
            // PENDING_REVIEW = waiting for holding decision
            // APPROVED = approved and applied to tax_case
            // REJECTED = rejected, tax_case not updated
            // GRANTED & NOT_GRANTED are deprecated with this new flow
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropColumn(['proposed_values', 'proposed_document_changes']);
        });
    }
};
