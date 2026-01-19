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
        Schema::table('skp_records', function (Blueprint $table) {
            $table->enum('user_routing_choice', ['refund', 'objection'])->nullable()->after('skp_type')->comment('User explicit choice: refund or objection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('skp_records', function (Blueprint $table) {
            $table->dropColumn('user_routing_choice');
        });
    }
};
