<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp2_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            // SP2 Data
            $table->string('sp2_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('receipt_date')->nullable();
            
            // Auditor Info
            $table->string('auditor_name')->nullable();
            $table->string('auditor_phone')->nullable();
            $table->string('auditor_email')->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->index('sp2_number');
        });

        Schema::create('sphp_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            // SPHP Data
            $table->string('sphp_number')->nullable();
            $table->date('sphp_issue_date')->nullable();
            $table->date('sphp_receipt_date')->nullable();
            
            // Audit Findings
            $table->decimal('royalty_finding', 15, 2)->nullable();
            $table->decimal('service_finding', 15, 2)->nullable();
            $table->decimal('other_finding', 15, 2)->nullable();
            $table->text('other_finding_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
        });

        Schema::create('skp_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            // SKP Data
            $table->string('skp_number');
            $table->date('issue_date');
            $table->date('receipt_date');
            $table->enum('skp_type', ['LB', 'NIHIL', 'KB'])->comment('LB=Lebih Bayar, NIHIL=Nihil, KB=Kurang Bayar');
            $table->decimal('skp_amount', 20, 2);
            
            // Corrections
            $table->text('audit_corrections')->nullable();
            $table->decimal('additional_corrections', 20, 2)->nullable();
            
            // Decision Routing
            $table->integer('next_stage')->nullable()->comment('5=Objection or 12=Refund');
            
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
            $table->index('skp_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skp_records');
        Schema::dropIfExists('sphp_records');
        Schema::dropIfExists('sp2_records');
    }
};
