<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fa_bookings', function (Blueprint $table) {
            $table->dropUnique('fa_bookings_unique_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('fa_bookings', function (Blueprint $table) {
            $table->unique(
                ['owner_type', 'owner_id', 'date', 'start_time', 'status'],
                'fa_bookings_unique_confirmed'
            );
        });
    }
};
