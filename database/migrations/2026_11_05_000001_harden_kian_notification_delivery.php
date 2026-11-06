<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->json('event_snapshot')->nullable();
            $table->index(['tax_case_id', 'stage_id', 'id'], 'notification_latest_stage');
        });
        Schema::table('notification_attempts', function (Blueprint $table) {
            $table->json('message_snapshot')->nullable();
            $table->json('recipient_snapshot')->nullable();
            $table->string('claim_token', 36)->nullable();
            $table->timestamp('send_boundary_at')->nullable();
        });
        // Existing installations: additive permission only, never replace assignments.
        DB::transaction(function () {
            $role = DB::table('roles')->where('code', 'ADMIN')->where('is_active', true)->lockForUpdate()->first();
            if (! $role) {
                return;
            }
            $permissions = json_decode($role->permissions ?? '[]', true);
            if (! is_array($permissions)) {
                return;
            } // ambiguous historical configuration stays closed
            if (! in_array('retry_notifications', $permissions, true)) {
                $permissions[] = 'retry_notifications';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('notification_logs')->whereNotNull('event_snapshot')->exists()
            || DB::table('notification_attempts')->whereNotNull('claim_token')->orWhereNotNull('message_snapshot')->exists()) {
            throw new RuntimeException('Preserve B18.1 delivery evidence: downgrade requires an explicit evidence migration.');
        }
        // Do not remove an independently assigned permission. Export evidence before downgrade.
        Schema::table('notification_attempts', fn (Blueprint $table) => $table->dropColumn(['message_snapshot', 'recipient_snapshot', 'claim_token', 'send_boundary_at']));
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notification_latest_stage');
            $table->dropColumn('event_snapshot');
        });
    }
};
