<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            // Revision tracking fields
            $table->enum('revision_status', ['CURRENT', 'IN_REVISION', 'REVISED'])->default('CURRENT')->after('submitted_at');
            $table->unsignedBigInteger('last_revision_id')->nullable()->after('revision_status');
            
            // Add index for revision queries
            $table->index('revision_status');
            $table->foreign('last_revision_id')->references('id')->on('revisions')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropForeign(['last_revision_id']);
            $table->dropIndex(['revision_status']);
            $table->dropColumn(['revision_status', 'last_revision_id']);
        });
    }
};
