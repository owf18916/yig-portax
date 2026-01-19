<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objection_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('objection_number');
            $table->date('submission_date');
            $table->decimal('objection_amount', 20, 2);
            $table->text('objection_grounds')->nullable();
            $table->text('supporting_evidence')->nullable();
            
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

        Schema::create('spuh_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            // Phase 1: SPUH Receipt
            $table->string('spuh_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('receipt_date')->nullable();
            
            // Phase 2: Reply (filled later)
            $table->string('reply_number')->nullable();
            $table->date('reply_date')->nullable();
            
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
        });

        Schema::create('objection_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('decision_number');
            $table->date('decision_date');
            $table->enum('decision_type', ['granted', 'partially_granted', 'rejected']);
            $table->decimal('decision_amount', 20, 2)->nullable();
            $table->text('decision_notes')->nullable();
            
            // Decision Routing
            $table->integer('next_stage')->nullable()->comment('8=Appeal or 12=Refund');
            
            // Workflow
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objection_decisions');
        Schema::dropIfExists('spuh_records');
        Schema::dropIfExists('objection_submissions');
    }
};
