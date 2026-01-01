<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend existing users table with new fields
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->nullable()->after('id');
            $table->unsignedBigInteger('role_id')->nullable()->after('entity_id');
            $table->string('phone')->nullable()->after('email');
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            
            // Foreign keys
            $table->foreign('entity_id')->references('id')->on('entities')->onDelete('restrict');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('restrict');
            
            // Indexes
            $table->index('entity_id');
            $table->index('role_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
            $table->dropForeign(['role_id']);
            $table->dropIndex(['entity_id']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'entity_id', 'role_id', 'phone', 'position', 'department',
                'last_login_at', 'is_active', 'deleted_at'
            ]);
        });
    }
};
