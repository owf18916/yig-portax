<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('permissions')->nullable(); // Array of permission strings
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
