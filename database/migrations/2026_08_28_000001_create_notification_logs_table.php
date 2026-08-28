<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stage_id');
            $table->string('notification_type', 50);
            $table->string('idempotency_key', 64);
            $table->string('current_status', 40);
            $table->unsignedInteger('total_attempts')->default(0);
            $table->text('reason')->nullable();
            $table->timestamp('first_queued_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->index(['tax_case_id', 'stage_id', 'notification_type']);
            $table->index(['entity_id', 'current_status', 'created_at']);
        });
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_log_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 40);
            $table->text('reason')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('initiated_type', 30);
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();
            $table->unique(['notification_log_id', 'attempt_number']);
            $table->index(['notification_log_id', 'status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('notification_attempts'); Schema::dropIfExists('notification_logs'); }
};
