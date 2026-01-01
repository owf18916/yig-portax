<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_cases', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('currency_id')->default(1); // IDR
            $table->unsignedBigInteger('case_status_id')->default(1); // OPEN
            
            // Identity
            $table->string('case_number')->unique();
            $table->enum('case_type', ['CIT', 'VAT']);
            
            // SPT Data (merged from spt_filings)
            $table->string('spt_number')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('received_date')->nullable();
            
            // Amounts
            $table->decimal('reported_amount', 20, 2)->comment('Immutable - starting point');
            $table->decimal('disputed_amount', 20, 2)->comment('Mutable - current value');
            $table->decimal('vat_in_amount', 20, 2)->nullable()->comment('For VAT cases');
            $table->decimal('vat_out_amount', 20, 2)->nullable()->comment('For VAT cases');
            
            // Workflow
            $table->integer('current_stage')->default(1);
            $table->boolean('is_completed')->default(false);
            $table->date('completed_date')->nullable();
            
            // Approval
            $table->text('description')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Refund
            $table->decimal('refund_amount', 20, 2)->nullable();
            $table->date('refund_date')->nullable();
            
            // Audit
            $table->unsignedBigInteger('last_updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('entity_id')->references('id')->on('entities')->onDelete('cascade');
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->onDelete('restrict');
            $table->foreign('period_id')->references('id')->on('periods')->onDelete('set null');
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('restrict');
            $table->foreign('case_status_id')->references('id')->on('case_statuses')->onDelete('restrict');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('last_updated_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index(['user_id', 'case_status_id']);
            $table->index(['entity_id', 'current_stage']);
            $table->index(['fiscal_year_id', 'case_type']);
            $table->index('case_number');
            $table->index('current_stage');
            $table->index('is_completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_cases');
    }
};
