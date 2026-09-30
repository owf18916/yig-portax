<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->string('target_type')->nullable()->after('stage_code');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->string('target_version')->nullable()->after('target_id');
            $table->json('audit_data')->nullable()->after('proposed_document_changes');
            $table->index(['target_type', 'target_id', 'stage_code', 'revision_status'], 'revisions_target_pending_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropIndex('revisions_target_pending_lookup');
            $table->dropColumn(['target_type', 'target_id', 'target_version', 'audit_data']);
        });
    }
};
