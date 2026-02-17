<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fa_bookings', function (Blueprint $table) {
            $table->id();

            $table->string('owner_type'); // the advisor (polymorphic)
            $table->unsignedBigInteger('owner_id');

            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('client_type')->nullable(); // who booked (polymorphic)
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('status')->default('confirmed'); // confirmed, cancelled
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'date', 'status']);
            $table->unique(
                ['owner_type', 'owner_id', 'date', 'start_time', 'status'],
                'fa_bookings_unique_confirmed'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fa_bookings');
    }
};
