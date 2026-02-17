<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fa_blocks', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('fa_blocks', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
