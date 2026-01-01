<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->year('year')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->index('year');
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_year_id');
            $table->string('period_code'); // YYYY-MM format
            $table->integer('year');
            $table->integer('month'); // 1-12
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->onDelete('restrict');
            $table->unique(['fiscal_year_id', 'period_code']);
            $table->index('year');
            $table->index(['fiscal_year_id', 'month']);
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('symbol');
            $table->integer('decimal_places')->default(2);
            $table->decimal('exchange_rate', 18, 2)->default(1.00);
            $table->timestamp('last_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('code');
        });

        Schema::create('case_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('stage_number')->nullable();
            $table->enum('category', ['active', 'terminal'])->default('active');
            $table->string('color')->nullable(); // For UI
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('code');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_statuses');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('fiscal_years');
    }
};
