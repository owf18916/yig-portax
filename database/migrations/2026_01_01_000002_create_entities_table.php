<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('entity_type', ['HOLDING', 'AFFILIATE'])->default('AFFILIATE');
            $table->unsignedBigInteger('parent_entity_id')->nullable();
            
            // Tax/Registration
            $table->string('tax_id')->unique(); // NPWP
            $table->string('registration_number')->nullable();
            
            // Address
            $table->text('business_address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            
            // Contact
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            
            // Business
            $table->string('industry_code')->nullable();
            $table->string('industry_name')->nullable();
            $table->decimal('annual_revenue', 20, 2)->nullable();
            $table->integer('employee_count')->nullable();
            
            // Status
            $table->enum('business_status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->date('established_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign Key
            $table->foreign('parent_entity_id')->references('id')->on('entities')->onDelete('restrict');
            
            // Indexes
            $table->index('code');
            $table->index('tax_id');
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
