<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kian_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('kian_number');
            $table->date('submission_date');
            $table->decimal('kian_amount', 20, 2);
            $table->text('kian_reason')->nullable();
            
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

        Schema::create('refund_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('refund_number');
            $table->decimal('refund_amount', 20, 2);
            $table->enum('refund_method', ['bank_transfer', 'check', 'credit'])->default('bank_transfer');
            $table->enum('refund_status', ['pending', 'approved', 'processed', 'completed', 'rejected'])->default('pending');
            
            $table->date('approved_date')->nullable();
            $table->date('processed_date')->nullable();
            
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
            $table->index('refund_status');
        });

        Schema::create('bank_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_process_id');
            
            $table->string('request_number');
            $table->string('instruction_number')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();
            
            $table->decimal('transfer_amount', 20, 2);
            $table->date('transfer_date')->nullable();
            $table->date('processed_date')->nullable();
            $table->string('receipt_number')->nullable();
            
            $table->enum('transfer_status', ['pending', 'processing', 'completed', 'rejected', 'cancelled'])->default('pending');
            $table->text('rejection_reason')->nullable();
            
            $table->unsignedBigInteger('created_by');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('refund_process_id')->references('id')->on('refund_processes')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            
            $table->index(['refund_process_id', 'transfer_status']);
            $table->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_requests');
        Schema::dropIfExists('refund_processes');
        Schema::dropIfExists('kian_submissions');
    }
};
