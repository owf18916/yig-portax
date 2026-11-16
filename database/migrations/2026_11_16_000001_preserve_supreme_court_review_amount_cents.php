<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supreme_court_submissions', function (Blueprint $table) {
            $table->decimal('review_amount', 17, 2)->nullable()->comment('Nilai - Review Amount')->change();
        });
    }

    public function down(): void
    {
        // Scale-zero rollback could silently destroy approved cents.
        throw new RuntimeException('Review amount cents require a separately reviewed rollback; automatic narrowing is unsafe.');
    }
};
