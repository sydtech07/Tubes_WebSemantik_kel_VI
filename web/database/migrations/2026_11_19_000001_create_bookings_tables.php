<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('screening_iri', 500);
            $table->string('movie_slug', 220);
            $table->string('cinema_id', 100);
            $table->dateTimeTz('show_start');
            $table->unsignedInteger('seat_count');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['screening_iri', 'status']);
        });

        Schema::create('booking_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('screening_iri', 500);
            $table->string('seat_code', 10);
            $table->timestamps();
            $table->unique(['screening_iri', 'seat_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_seats');
        Schema::dropIfExists('bookings');
    }
};
