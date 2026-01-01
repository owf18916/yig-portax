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
            $table->unsignedBigInteger('tax_case_id');
            
            // Polymorphic
            $table->string('documentable_type')->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            
            $table->integer('stage_number')->nullable();
            $table->string('document_type');
            
            // File Info
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('file_size'); // in bytes
            
            $table->text('description')->nullable();
            
            // Upload Info
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamp('uploaded_at');
            
            // Verification
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
            // Audit
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('verified_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index(['tax_case_id', 'stage_number']);
            $table->index(['documentable_type', 'documentable_id']);
            $table->index('uploaded_at');
            $table->index('is_verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
