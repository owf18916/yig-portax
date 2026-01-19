<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supreme_court_decision_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_case_id')
                ->constrained('tax_cases')
                ->cascadeOnDelete();

            // Supreme Court Decision specific fields (Stage 12)
            $table->string('keputusan_pk_number')->nullable();
            $table->date('keputusan_pk_date')->nullable();
            
            // Decision field - determines routing
            $table->enum('keputusan_pk', ['dikabulkan', 'dikabulkan_sebagian', 'ditolak'])->nullable();
            
            // Amount field
            $table->decimal('keputusan_pk_amount', 15, 2)->default(0);
            
            // Notes field
            $table->text('keputusan_pk_notes')->nullable();
            
            // Decision routing field (refund or kian)
            $table->enum('next_action', ['refund', 'kian'])->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supreme_court_decision_records');
    }
};
