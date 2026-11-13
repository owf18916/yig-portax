<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kian_submissions', function (Blueprint $table) {
            $table->decimal('write_off_amount', 20, 2)->default('0.00');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: recorded KIAN write-off amounts must not be dropped.');
    }
};
