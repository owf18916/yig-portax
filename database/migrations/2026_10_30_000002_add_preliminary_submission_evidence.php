<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Preliminary evidence migration requires an engine-specific preflight for this database driver.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE preliminary_refund_requests DROP CONSTRAINT preliminary_refund_requests_approval_status_check');
            DB::statement("ALTER TABLE preliminary_refund_requests ADD CONSTRAINT preliminary_refund_requests_approval_status_check CHECK (approval_status IN ('PENDING', 'POSTPONED', 'SUBMITTED', 'APPROVED', 'REJECTED'))");
            DB::statement("ALTER TABLE preliminary_refund_requests ALTER COLUMN approval_status SET DEFAULT 'SUBMITTED'");
        } elseif (DB::getDriverName() !== 'sqlite') {
            Schema::table('preliminary_refund_requests', function (Blueprint $table) {
                $table->enum('approval_status', ['PENDING', 'POSTPONED', 'SUBMITTED', 'APPROVED', 'REJECTED'])->default('SUBMITTED')->change();
            });
        }
        Schema::table('preliminary_refund_requests', function (Blueprint $table) {
            // SQLite rebuilds for foreign keys. Keep the enum declaration in
            // that same rebuild, otherwise introspection loses its CHECK.
            if (DB::getDriverName() === 'sqlite') {
                $table->enum('approval_status', ['PENDING', 'POSTPONED', 'SUBMITTED', 'APPROVED', 'REJECTED'])->default('SUBMITTED')->change();
            }
            $table->foreignId('submission_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('decision_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->date('decision_date')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: preserve preliminary statuses and authoritative document associations.');
    }
};
