<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fa_blocks', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type'); // e.g. user
            $table->unsignedBigInteger('owner_id');

            $table->date('date');
            $table->time('start_time')->nullable(); // null = all-day
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_blocks');
    }
};
