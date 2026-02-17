<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fa_busy_cache', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');

            $table->date('date');
            $table->string('calendars_hash');
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'date', 'calendars_hash'], 'busy_cache_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_busy_cache');
    }
};
