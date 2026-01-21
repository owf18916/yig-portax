<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add next_action field to supreme_court_decisions if missing
     */
    public function up(): void
    {
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            if (!Schema::hasColumn('supreme_court_decisions', 'next_action')) {
                $table->enum('next_action', ['refund', 'kian'])->nullable()->after('decision_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            if (Schema::hasColumn('supreme_court_decisions', 'next_action')) {
                $table->dropColumn('next_action');
            }
        });
    }
};
