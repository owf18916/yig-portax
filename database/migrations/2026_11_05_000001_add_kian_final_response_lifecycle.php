<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $driver = DB::getDriverName();
            if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
                throw new RuntimeException('KIAN lifecycle supports PostgreSQL and disposable SQLite only.');
            }
            if ($driver === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement('LOCK TABLE kian_submissions IN ACCESS EXCLUSIVE MODE');
                // Laravel PostgreSQL enum is a varchar with a CHECK, not a native enum.
                // Refuse unexpected status checks rather than weakening unknown constraints.
                $checks = DB::select("SELECT c.conname, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attname = 'status' WHERE c.conrelid = 'kian_submissions'::regclass AND c.contype = 'c' AND a.attnum = ANY(c.conkey)");
                if (count($checks) !== 1) throw new RuntimeException('Expected one KIAN status CHECK; inspect schema before upgrading.');
                preg_match_all("/'([^']+)'/", $checks[0]->definition, $matches);
                $values = $matches[1];
                sort($values);
                if ($values !== ['approved', 'draft', 'rejected', 'submitted']) throw new RuntimeException('Unexpected KIAN status CHECK; no historical mapping authorized.');
                $name = '"'.str_replace('"', '""', $checks[0]->conname).'"';
                DB::statement('ALTER TABLE kian_submissions DROP CONSTRAINT '.$name);
                DB::statement("ALTER TABLE kian_submissions ADD CONSTRAINT kian_submissions_status_check CHECK (status IN ('draft','submitted','approved','rejected','responded','closed'))");
            } else {
                Schema::table('kian_submissions', fn (Blueprint $table) => $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'responded', 'closed'])->default('draft')->change());
            }
            Schema::table('kian_submissions', function (Blueprint $table) {
                $table->string('response_status', 24)->nullable();
                $table->string('response_reference')->nullable();
                $table->date('response_issued_date')->nullable();
                $table->date('response_received_date')->nullable();
                $table->decimal('approved_amount', 20, 2)->nullable();
                $table->text('response_notes')->nullable();
                $table->foreignId('response_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
                $table->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('responded_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('closed_at')->nullable();
            });
            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE kian_submissions ADD CONSTRAINT kian_final_response_complete CHECK (status NOT IN ('responded','closed') OR (stage_id IN (4,7,10,12) AND stage_id IS NOT NULL AND response_status IS NOT NULL AND response_status IN ('ACCEPTED','PARTIALLY_ACCEPTED','REJECTED') AND response_reference IS NOT NULL AND length(trim(response_reference)) > 0 AND response_issued_date IS NOT NULL AND response_received_date IS NOT NULL AND response_document_id IS NOT NULL AND responded_by IS NOT NULL AND responded_at IS NOT NULL AND (approved_amount IS NULL OR (approved_amount >= 0 AND approved_amount <= kian_amount))))");
                DB::statement("ALTER TABLE kian_submissions ADD CONSTRAINT kian_closure_complete CHECK (status <> 'closed' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL))");
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: KIAN final evidence must not be dropped or remapped.');
    }
};
