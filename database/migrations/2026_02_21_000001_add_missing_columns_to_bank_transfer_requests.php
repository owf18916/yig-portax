<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds missing columns to bank_transfer_requests table:
     * - transfer_number: Unique identifier for each transfer request
     * - account_name: Name associated with the account
     */
    public function up(): void
    {
        Schema::table('bank_transfer_requests', function (Blueprint $table) {
            // Add transfer_number if it doesn't exist
            if (!Schema::hasColumn('bank_transfer_requests', 'transfer_number')) {
                $table->string('transfer_number')->nullable()->after('request_number');
            }
            
            // Add account_name if it doesn't exist
            if (!Schema::hasColumn('bank_transfer_requests', 'account_name')) {
                $table->string('account_name')->nullable()->after('account_holder');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transfer_requests', function (Blueprint $table) {
            if (Schema::hasColumn('bank_transfer_requests', 'transfer_number')) {
                $table->dropColumn('transfer_number');
            }
            
            if (Schema::hasColumn('bank_transfer_requests', 'account_name')) {
                $table->dropColumn('account_name');
            }
        });
    }
};
