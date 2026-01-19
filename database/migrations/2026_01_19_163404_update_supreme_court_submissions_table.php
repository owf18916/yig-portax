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
        Schema::table('supreme_court_submissions', function (Blueprint $table) {
            // Add Stage 11 specific fields (rename from generic submission fields)
            // These are the 3 fields from Stage 11 documentation
            
            // Add new columns
            if (!Schema::hasColumn('supreme_court_submissions', 'supreme_court_letter_number')) {
                $table->string('supreme_court_letter_number')->nullable()->comment('Nomor Surat Peninjauan Kembali');
            }
            
            if (!Schema::hasColumn('supreme_court_submissions', 'review_amount')) {
                $table->decimal('review_amount', 15, 0)->nullable()->comment('Nilai - Review Amount');
            }
            
            // Rename or adapt existing submission_amount to review_amount for consistency
            // Keep submission_amount for backward compatibility but will use review_amount in code
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supreme_court_submissions', function (Blueprint $table) {
            $table->dropColumnIfExists('supreme_court_letter_number');
            $table->dropColumnIfExists('review_amount');
        });
    }
};
