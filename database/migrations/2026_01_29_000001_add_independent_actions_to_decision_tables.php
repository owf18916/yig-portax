<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add independent action fields to all decision tables:
     * - create_refund: Whether to create a refund process
     * - refund_amount: Amount to refund (if create_refund is true)
     * - continue_to_next_stage: Whether to continue to next stage
     */
    public function up(): void
    {
        // SKP Records
        Schema::table('skp_records', function (Blueprint $table) {
            $table->boolean('create_refund')->default(false)->after('user_routing_choice')->comment('Whether to create a refund');
            $table->decimal('refund_amount', 20, 2)->nullable()->after('create_refund')->comment('Refund amount if creating refund');
            $table->boolean('continue_to_next_stage')->default(false)->after('refund_amount')->comment('Whether to continue to next stage');
        });

        // Objection Decisions
        Schema::table('objection_decisions', function (Blueprint $table) {
            $table->boolean('create_refund')->default(false)->after('next_stage')->comment('Whether to create a refund');
            $table->decimal('refund_amount', 20, 2)->nullable()->after('create_refund')->comment('Refund amount if creating refund');
            $table->boolean('continue_to_next_stage')->default(false)->after('refund_amount')->comment('Whether to continue to next stage');
        });

        // Appeal Decisions
        Schema::table('appeal_decisions', function (Blueprint $table) {
            $table->boolean('create_refund')->default(false)->after('next_stage')->comment('Whether to create a refund');
            $table->decimal('refund_amount', 20, 2)->nullable()->after('create_refund')->comment('Refund amount if creating refund');
            $table->boolean('continue_to_next_stage')->default(false)->after('refund_amount')->comment('Whether to continue to next stage');
        });

        // Supreme Court Decisions
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            $table->boolean('create_refund')->default(false)->after('next_action')->comment('Whether to create a refund');
            $table->decimal('refund_amount', 20, 2)->nullable()->after('create_refund')->comment('Refund amount if creating refund');
            $table->boolean('continue_to_next_stage')->default(false)->after('refund_amount')->comment('Whether to continue to next stage (N/A for final decision)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // SKP Records
        Schema::table('skp_records', function (Blueprint $table) {
            $table->dropColumn(['create_refund', 'refund_amount', 'continue_to_next_stage']);
        });

        // Objection Decisions
        Schema::table('objection_decisions', function (Blueprint $table) {
            $table->dropColumn(['create_refund', 'refund_amount', 'continue_to_next_stage']);
        });

        // Appeal Decisions
        Schema::table('appeal_decisions', function (Blueprint $table) {
            $table->dropColumn(['create_refund', 'refund_amount', 'continue_to_next_stage']);
        });

        // Supreme Court Decisions
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            $table->dropColumn(['create_refund', 'refund_amount', 'continue_to_next_stage']);
        });
    }
};
