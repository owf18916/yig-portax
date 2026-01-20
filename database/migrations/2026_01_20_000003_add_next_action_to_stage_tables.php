<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add Next Action metadata fields to ALL stage tables
     * These are per-stage follow-up actions and comments
     */
    public function up(): void
    {
        // Stage 2: SP2 Record
        if (Schema::hasTable('sp2_records') && !Schema::hasColumn('sp2_records', 'next_action')) {
            Schema::table('sp2_records', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 3: SPHP Record
        if (Schema::hasTable('sphp_records') && !Schema::hasColumn('sphp_records', 'next_action')) {
            Schema::table('sphp_records', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 4: SKP Record
        if (Schema::hasTable('skp_records') && !Schema::hasColumn('skp_records', 'next_action')) {
            Schema::table('skp_records', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 5: Objection Submission
        if (Schema::hasTable('objection_submissions') && !Schema::hasColumn('objection_submissions', 'next_action')) {
            Schema::table('objection_submissions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 6: SPUH Record
        if (Schema::hasTable('spuh_records') && !Schema::hasColumn('spuh_records', 'next_action')) {
            Schema::table('spuh_records', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 7: Objection Decision
        if (Schema::hasTable('objection_decisions') && !Schema::hasColumn('objection_decisions', 'next_action')) {
            Schema::table('objection_decisions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 8: Appeal Submission
        if (Schema::hasTable('appeal_submissions') && !Schema::hasColumn('appeal_submissions', 'next_action')) {
            Schema::table('appeal_submissions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 9: Appeal Explanation Request
        if (Schema::hasTable('appeal_explanation_requests') && !Schema::hasColumn('appeal_explanation_requests', 'next_action')) {
            Schema::table('appeal_explanation_requests', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 10: Appeal Decision
        if (Schema::hasTable('appeal_decisions') && !Schema::hasColumn('appeal_decisions', 'next_action')) {
            Schema::table('appeal_decisions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 11: Supreme Court Submission
        if (Schema::hasTable('supreme_court_submissions') && !Schema::hasColumn('supreme_court_submissions', 'next_action')) {
            Schema::table('supreme_court_submissions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 12: Supreme Court Decision Record
        if (Schema::hasTable('supreme_court_decision_records') && !Schema::hasColumn('supreme_court_decision_records', 'next_action_due_date')) {
            Schema::table('supreme_court_decision_records', function (Blueprint $table) {
                // next_action already exists, just add the other two
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 13: Refund Process
        if (Schema::hasTable('refund_processes') && !Schema::hasColumn('refund_processes', 'next_action')) {
            Schema::table('refund_processes', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }

        // Stage 14/16: KIAN Submission
        if (Schema::hasTable('kian_submissions') && !Schema::hasColumn('kian_submissions', 'next_action')) {
            Schema::table('kian_submissions', function (Blueprint $table) {
                $table->text('next_action')->nullable()->comment('Next action for this stage');
                $table->date('next_action_due_date')->nullable()->comment('Due date for next action');
                $table->text('status_comment')->nullable()->comment('Status comment for this stage');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'sp2_records',
            'sphp_records',
            'skp_records',
            'objection_submissions',
            'spuh_records',
            'objection_decisions',
            'appeal_submissions',
            'appeal_explanation_requests',
            'appeal_decisions',
            'supreme_court_submissions',
            'supreme_court_decision_records',
            'refund_processes',
            'kian_submissions'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $columns = [];
                    if (Schema::hasColumn($table->getTable(), 'next_action')) {
                        $columns[] = 'next_action';
                    }
                    if (Schema::hasColumn($table->getTable(), 'next_action_due_date')) {
                        $columns[] = 'next_action_due_date';
                    }
                    if (Schema::hasColumn($table->getTable(), 'status_comment')) {
                        $columns[] = 'status_comment';
                    }
                    if (!empty($columns)) {
                        $table->dropColumn($columns);
                    }
                });
            }
        }
    }
};
