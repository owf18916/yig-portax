<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds:
     * - spt_type field to tax_cases table for differentiating case types
     * - preliminary_refund_requests table for "Pengembalian Pendahuluan" flow
     */
    public function up(): void
    {
        // Add spt_type to tax_cases table
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->string('spt_type', 50)
                ->nullable()
                ->after('spt_number')
                ->comment('SPT Type: Pengembalian Pendahuluan, Restitusi, Kompensasi, etc');
            
            $table->index('spt_type');
        });

        // Create preliminary_refund_requests table
        Schema::create('preliminary_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id')->unique();
            
            // Request details
            $table->string('request_number')->nullable();
            $table->date('submission_date')->nullable();
            $table->decimal('requested_amount', 20, 2);
            
            // Approval workflow
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->decimal('approved_amount', 20, 2)->default(0);
            $table->date('approved_date')->nullable();
            
            // Notes and tracking
            $table->text('notes')->nullable();
            $table->text('next_action')->nullable();
            $table->date('next_action_due_date')->nullable();
            $table->text('status_comment')->nullable();
            
            // Audit
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Keys
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('approval_status');
            $table->index('submission_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preliminary_refund_requests');
        
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropIndex(['spt_type']);
            $table->dropColumn('spt_type');
        });
    }
};
