<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fa_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('external_calendar_id');
            $table->string('name');
            $table->boolean('included')->default(true);
            $table->boolean('primary')->default(false);
            $table->timestamps();

            $table->index(['connection_id', 'included']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_sources');
    }
};
