<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old table and recreate with new structure
        Schema::dropIfExists('appeal_explanation_requests');
        
        Schema::create('appeal_explanation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_case_id')
                ->constrained('tax_cases')
                ->cascadeOnDelete();

            // Phase 1: Explanation Request Receipt (required)
            $table->string('request_number')->nullable()->comment('Nomor Surat Permintaan Penjelasan Banding');
            $table->date('request_issue_date')->nullable()->comment('Tanggal Diterbitkan');
            $table->date('request_receipt_date')->nullable()->comment('Tanggal Diterima');
            
            // Phase 2: Explanation Submission (optional, filled later by user)
            $table->string('explanation_letter_number')->nullable()->comment('Nomor Surat Penjelasan');
            $table->date('explanation_submission_date')->nullable()->comment('Tanggal Dilaporkan');
            
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeal_explanation_requests');
        
        // Recreate the old table structure
        Schema::create('appeal_explanation_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('request_number')->nullable();
            $table->date('request_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('explanation_required')->nullable();
            $table->text('explanation_provided')->nullable();
            $table->date('explanation_date')->nullable();
            
            // Workflow
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }
};
