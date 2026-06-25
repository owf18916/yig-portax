<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->unique(
                ['entity_id', 'case_type', 'period_id'],
                'tax_cases_entity_type_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropUnique('tax_cases_entity_type_period_unique');
        });
    }
};
