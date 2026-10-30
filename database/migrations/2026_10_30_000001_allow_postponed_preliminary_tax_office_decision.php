<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Laravel's original enum is a varchar with this named CHECK.
            DB::statement('ALTER TABLE preliminary_refund_requests DROP CONSTRAINT preliminary_refund_requests_approval_status_check');
            DB::statement("ALTER TABLE preliminary_refund_requests ADD CONSTRAINT preliminary_refund_requests_approval_status_check CHECK (approval_status IN ('PENDING', 'APPROVED', 'REJECTED', 'POSTPONED'))");

            return;
        }

        Schema::table('preliminary_refund_requests', function (Blueprint $table) {
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED', 'POSTPONED'])->default('PENDING')->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain POSTPONED evidence; roll back application code without shrinking the status constraint.');
    }
};
