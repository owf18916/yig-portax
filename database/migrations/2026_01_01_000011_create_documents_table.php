<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            
            // Polymorphic relationship
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            
            // Tax case reference for easy filtering
            $table->unsignedBigInteger('tax_case_id');
            
            // Document classification
            $table->string('document_type');
            $table->string('stage_code');
            
            // File information
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_mime_type')->nullable();
            $table->bigInteger('file_size')->nullable(); // in bytes
            $table->string('hash')->nullable(); // For duplicate detection and integrity check
            
            // Meta
            $table->text('description')->nullable();
            
            // Upload tracking
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->dateTime('uploaded_at');
            
            // Versioning
            $table->integer('version')->default(1);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            
            // Workflow status
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED', 'DELETED'])->default('ACTIVE');
            
            // Audit timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('previous_version_id')->references('id')->on('documents')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['documentable_type', 'documentable_id']);
            $table->index('tax_case_id');
            $table->index('document_type');
            $table->index('stage_code');
            $table->index('uploaded_by');
            $table->index('previous_version_id');
            $table->index('status');
            $table->index('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
