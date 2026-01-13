<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Drop foreign keys first before dropping columns
            $table->dropForeign(['verified_by']);
        });

        Schema::table('documents', function (Blueprint $table) {
            // Remove old columns that are not needed
            $table->dropColumn(['stage_number', 'stored_filename', 'mime_type', 'is_verified', 'verified_by', 'verified_at', 'verification_notes']);
        });

        Schema::table('documents', function (Blueprint $table) {
            // Update documentable columns to be NOT NULLABLE
            $table->string('documentable_type')->nullable(false)->change();
            $table->unsignedBigInteger('documentable_id')->nullable(false)->change();
            
            // Add new columns
            $table->string('stage_code')->after('documentable_id');
            $table->string('file_mime_type')->nullable()->after('file_path');
            $table->string('hash')->nullable()->after('file_size');
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
            $table->integer('version')->default(1)->after('uploaded_at');
            $table->unsignedBigInteger('previous_version_id')->nullable()->after('version');
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED', 'DELETED'])->default('ACTIVE')->after('previous_version_id');
            
            // Add foreign key for previous_version_id
            $table->foreign('previous_version_id')->references('id')->on('documents')->onDelete('set null');
            
            // Add new indexes
            $table->index('stage_code');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Drop foreign key before dropping columns
            $table->dropForeign(['previous_version_id']);
            $table->dropColumn(['stage_code', 'file_mime_type', 'hash', 'version', 'previous_version_id', 'status']);
        });
    }
};
