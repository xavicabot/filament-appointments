<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fa_rules', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type'); // e.g. user
            $table->unsignedBigInteger('owner_id');

            $table->unsignedTinyInteger('weekday'); // 1=Mon ... 7=Sun (ISO-8601)
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('interval_minutes')->default(30);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'weekday', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_rules');
    }
};
