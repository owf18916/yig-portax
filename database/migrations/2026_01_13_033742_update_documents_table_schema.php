<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Drop foreign keys if they exist (use skipOnError for safety)
            // The verified_by foreign key may not exist in current schema
            try {
                if (Schema::hasColumn('documents', 'verified_by')) {
                    $table->dropForeign('documents_verified_by_foreign');
                }
            } catch (\Exception $e) {
                // Foreign key doesn't exist, skip
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            // Remove old columns only if they exist
            $columns = ['stage_number', 'stored_filename', 'mime_type', 'is_verified', 'verified_by', 'verified_at', 'verification_notes'];
            $existing = [];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('documents', $column)) {
                    $existing[] = $column;
                }
            }
            
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            // Update documentable columns to be NOT NULLABLE
            if (Schema::hasColumn('documents', 'documentable_type')) {
                $table->string('documentable_type')->nullable(false)->change();
            }
            if (Schema::hasColumn('documents', 'documentable_id')) {
                $table->unsignedBigInteger('documentable_id')->nullable(false)->change();
            }
            
            // Add new columns if they don't exist
            if (!Schema::hasColumn('documents', 'stage_code')) {
                $table->string('stage_code')->after('documentable_id');
            }
            if (!Schema::hasColumn('documents', 'file_mime_type')) {
                $table->string('file_mime_type')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('documents', 'hash')) {
                $table->string('hash')->nullable()->after('file_size');
            }
            if (!Schema::hasColumn('documents', 'version')) {
                $table->integer('version')->default(1)->after('uploaded_at');
            }
            if (!Schema::hasColumn('documents', 'previous_version_id')) {
                $table->unsignedBigInteger('previous_version_id')->nullable()->after('version');
            }
            if (!Schema::hasColumn('documents', 'status')) {
                $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED', 'DELETED'])->default('ACTIVE')->after('previous_version_id');
            }
            
            // Add foreign key for previous_version_id if not exists
            if (!Schema::hasColumn('documents', 'previous_version_id')) {
                $table->foreign('previous_version_id')->references('id')->on('documents')->onDelete('set null');
            }
            
            // Add new indexes
            if (!Schema::hasColumn('documents', 'stage_code')) {
                $table->index('stage_code');
            }
            if (!Schema::hasColumn('documents', 'status')) {
                $table->index('status');
            }
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
