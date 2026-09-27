<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp2_records', function (Blueprint $table) {
            $table->string('auditor_position')->nullable();
        });
        Schema::table('skp_records', function (Blueprint $table) {
            $table->date('skp_due_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('skp_records', fn (Blueprint $table) => $table->dropColumn('skp_due_date'));
        Schema::table('sp2_records', fn (Blueprint $table) => $table->dropColumn('auditor_position'));
    }
};
