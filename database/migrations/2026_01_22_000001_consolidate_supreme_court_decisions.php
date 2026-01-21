<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration consolidates the Supreme Court Decision stage (Stage 12)
     * from using separate tables (supreme_court_decisions vs supreme_court_decision_records)
     * into a single table with English field names.
     */
    public function up(): void
    {
        // Drop the old supreme_court_decision_records table
        // as all data has been consolidated to supreme_court_decisions
        Schema::dropIfExists('supreme_court_decision_records');
    }

    public function down(): void
    {
        // Recreate the old table structure if needed for rollback
        Schema::create('supreme_court_decision_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_case_id')
                ->constrained('tax_cases')
                ->cascadeOnDelete();

            $table->string('keputusan_pk_number')->nullable();
            $table->date('keputusan_pk_date')->nullable();
            $table->enum('keputusan_pk', ['dikabulkan', 'dikabulkan_sebagian', 'ditolak'])->nullable();
            $table->decimal('keputusan_pk_amount', 15, 2)->default(0);
            $table->text('keputusan_pk_notes')->nullable();
            $table->enum('next_action', ['refund', 'kian'])->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }
};
