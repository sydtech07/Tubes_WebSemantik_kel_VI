<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinema_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('cinema_id', 100);
            $table->unsignedTinyInteger('rating');
            $table->timestamps();
            $table->unique(['user_id', 'cinema_id']);
            $table->index('cinema_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinema_reviews');
    }
};
