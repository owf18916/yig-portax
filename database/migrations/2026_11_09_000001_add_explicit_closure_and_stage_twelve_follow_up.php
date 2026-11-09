<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_cases', function (Blueprint $table) {
            // NULL is unresolved legacy provenance; never backfill from is_completed.
            $table->boolean('explicitly_closed')->nullable();
        });
        Schema::table('supreme_court_decisions', function (Blueprint $table) {
            $table->text('follow_up_next_action')->nullable();
            $table->date('next_action_due_date')->nullable();
            $table->text('status_comment')->nullable();
        });
    }

    // Forward-only: rollback must not discard closure provenance or operational notes.
    public function down(): void
    {
        throw new LogicException('Forward-only corrective; preserve closure provenance and follow-up data.');
    }
};
