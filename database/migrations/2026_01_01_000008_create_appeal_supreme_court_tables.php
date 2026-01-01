<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeal_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('appeal_number');
            $table->string('dispute_number')->nullable();
            $table->date('submission_date');
            $table->decimal('appeal_amount', 20, 2);
            $table->text('appeal_grounds')->nullable();
            
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

        Schema::create('appeal_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('decision_number');
            $table->date('decision_date');
            $table->enum('decision_type', ['granted', 'partially_granted', 'rejected', 'skp_kb']);
            $table->decimal('decision_amount', 20, 2)->nullable();
            $table->text('decision_notes')->nullable();
            
            // Decision Routing
            $table->integer('next_stage')->nullable()->comment('11=Supreme Court or 12=Refund');
            
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

        Schema::create('supreme_court_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('submission_number');
            $table->date('submission_date');
            $table->decimal('submission_amount', 20, 2);
            $table->text('legal_basis')->nullable();
            $table->enum('review_type', ['cassation', 'review'])->default('cassation');
            
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

        Schema::create('supreme_court_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            $table->string('decision_number');
            $table->date('decision_date');
            $table->enum('decision_type', ['granted', 'rejected', 'partially_granted']);
            $table->decimal('decision_amount', 20, 2)->nullable();
            $table->text('decision_notes')->nullable();
            
            // Always routes to Refund
            $table->integer('next_stage')->default(12);
            
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
        Schema::dropIfExists('supreme_court_decisions');
        Schema::dropIfExists('supreme_court_submissions');
        Schema::dropIfExists('appeal_decisions');
        Schema::dropIfExists('appeal_explanation_requests');
        Schema::dropIfExists('appeal_submissions');
    }
};
