<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->string('spt_position_type', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            $table->dropColumn('spt_position_type');
        });
    }
};
