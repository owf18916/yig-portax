<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration refactors the refund system to:
     * 1. Allow MULTIPLE refund processes for PRELIMINARY stage (stage_id=0)
     * 2. Maintain ONE refund per stage for decision stages (4,7,10,12)
     * 3. Add missing date columns to bank_transfer_requests
     * 4. Enable full Refund Stage 1-4 tracking (replaces Stage 13-15 naming)
     * 
     * Notes:
     * - Old unique constraint UNIQUE(tax_case_id, stage_id) will be replaced
     * - NEW constraint: UNIQUE(tax_case_id, stage_id) WHERE stage_id != 0
     * - Since MySQL 5.7 doesn't support partial indexes, we'll use application logic
     * - For now, we drop the old constraint and add validation in RefundProcess model
     */
    public function up(): void
    {
        Schema::table('refund_processes', function (Blueprint $table) {
            // Drop the old unique constraint if it exists
            // This allows multiple refunds for stage_id=0 (PRELIMINARY)
            try {
                $table->dropUnique(['tax_case_id', 'stage_id']);
            } catch (\Exception $e) {
                // Already dropped or doesn't exist - that's fine
                \Illuminate\Support\Facades\Log::info('Unique constraint already dropped: ' . $e->getMessage());
            }
        });

        // Add a REGULAR index instead (for query performance without enforcing uniqueness)
        Schema::table('refund_processes', function (Blueprint $table) {
            if (!Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_stage_id_index')) {
                $table->index(['tax_case_id', 'stage_id']);
            }
        });

        // Now update bank_transfer_requests table with missing columns
        Schema::table('bank_transfer_requests', function (Blueprint $table) {
            // Add request_date - when the refund request was initially created
            if (!Schema::hasColumn('bank_transfer_requests', 'request_date')) {
                $table->date('request_date')
                    ->nullable()
                    ->after('request_number')
                    ->comment('Tanggal permintaan refund dibuat');
            }

            // Add instruction_issue_date - when bank instruction was issued
            if (!Schema::hasColumn('bank_transfer_requests', 'instruction_issue_date')) {
                $table->date('instruction_issue_date')
                    ->nullable()
                    ->after('instruction_number')
                    ->comment('Tanggal instruksi transfer dikeluarkan');
            }

            // Add instruction_received_date - when instruction was received
            if (!Schema::hasColumn('bank_transfer_requests', 'instruction_received_date')) {
                $table->date('instruction_received_date')
                    ->nullable()
                    ->after('instruction_issue_date')
                    ->comment('Tanggal instruksi transfer diterima');
            }

            // Add received_date - when refund was actually received
            if (!Schema::hasColumn('bank_transfer_requests', 'received_date')) {
                $table->date('received_date')
                    ->nullable()
                    ->after('processed_date')
                    ->comment('Tanggal refund benar-benar diterima');
            }

            // Add received_amount - actual amount received (may differ from transfer_amount)
            if (!Schema::hasColumn('bank_transfer_requests', 'received_amount')) {
                $table->decimal('received_amount', 20, 2)
                    ->nullable()
                    ->after('transfer_amount')
                    ->comment('Jumlah refund yang benar-benar diterima');
            }
        });

        /**
         * ENUM VALUE MAPPING DOCUMENTATION
         * 
         * The existing enum values are flexible enough for our Refund Stage 1-4 flow:
         * 
         * refund_processes.refund_status enum:
         *   - 'pending' = Refund Stage 1 (Recently initiated)
         *   - 'approved' = Refund Stage 2-3 (Approved & awaiting transfer)
         *   - 'processed' = Transfer in progress (Refund Stage 3)
         *   - 'completed' = Refund Stage 4 (Received)
         *   - 'rejected' = Rejected
         * 
         * bank_transfer_requests.transfer_status enum:
         *   - 'pending' = Refund Stage 1 (Initial request, not yet submitted)
         *   - 'processing' = Refund Stage 2-3 (Transfer request created → instruction received)
         *   - 'completed' = Refund Stage 4 (Transfer completed & received)
         *   - 'cancelled' = Cancelled
         *   - 'rejected' = Rejected
         * 
         * No enum changes needed - existing values support the flow!
         */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transfer_requests', function (Blueprint $table) {
            // Drop newly added columns
            if (Schema::hasColumn('bank_transfer_requests', 'request_date')) {
                $table->dropColumn('request_date');
            }
            if (Schema::hasColumn('bank_transfer_requests', 'instruction_issue_date')) {
                $table->dropColumn('instruction_issue_date');
            }
            if (Schema::hasColumn('bank_transfer_requests', 'instruction_received_date')) {
                $table->dropColumn('instruction_received_date');
            }
            if (Schema::hasColumn('bank_transfer_requests', 'received_date')) {
                $table->dropColumn('received_date');
            }
            if (Schema::hasColumn('bank_transfer_requests', 'received_amount')) {
                $table->dropColumn('received_amount');
            }
        });

        Schema::table('refund_processes', function (Blueprint $table) {
            // Drop the new regular index
            if (Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_stage_id_index')) {
                $table->dropIndex(['tax_case_id', 'stage_id']);
            }

            // Re-add the unique constraint for backward compatibility
            // This will enforce single refund per stage across the board
            if (!Schema::hasIndex('refund_processes', 'refund_processes_tax_case_id_stage_id_unique')) {
                $table->unique(['tax_case_id', 'stage_id']);
            }
        });
    }
};
