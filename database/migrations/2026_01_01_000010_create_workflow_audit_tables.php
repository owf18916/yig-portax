<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id');
            
            $table->integer('stage_from')->nullable();
            $table->integer('stage_to');
            $table->enum('action', ['submitted', 'approved', 'routed', 'skipped', 'rejected'])->default('submitted');
            $table->string('decision_point')->nullable();
            $table->string('decision_value')->nullable();
            
            $table->unsignedBigInteger('user_id');
            $table->text('notes')->nullable();
            
            $table->timestamp('created_at');
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            
            $table->index(['tax_case_id', 'created_at']);
            $table->index('stage_to');
            $table->index('stage_from');
        });

        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_case_id');
            $table->unsignedBigInteger('old_status_id')->nullable();
            $table->unsignedBigInteger('new_status_id');
            $table->unsignedBigInteger('changed_by');
            $table->text('reason')->nullable();
            
            $table->timestamp('created_at');
            
            $table->foreign('tax_case_id')->references('id')->on('tax_cases')->onDelete('cascade');
            $table->foreign('old_status_id')->references('id')->on('case_statuses')->onDelete('set null');
            $table->foreign('new_status_id')->references('id')->on('case_statuses')->onDelete('restrict');
            $table->foreign('changed_by')->references('id')->on('users')->onDelete('restrict');
            
            $table->index(['tax_case_id', 'created_at']);
            $table->index('old_status_id');
            $table->index('new_status_id');
        });

        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->string('revisable_type');
            $table->unsignedBigInteger('revisable_id');
            
            $table->enum('revision_status', ['requested', 'approved', 'rejected', 'implemented'])->default('requested');
            $table->json('original_data')->nullable();
            $table->json('revised_data')->nullable();
            
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');
            
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();
            
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            
            $table->index(['revisable_type', 'revisable_id']);
            $table->index('revision_status');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            
            $table->unsignedBigInteger('user_id');
            $table->enum('action', ['created', 'updated', 'deleted', 'approved', 'submitted', 'rejected'])->default('updated');
            $table->string('model_name');
            
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            
            $table->timestamp('performed_at');
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('performed_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('status_histories');
        Schema::dropIfExists('workflow_histories');
    }
};
