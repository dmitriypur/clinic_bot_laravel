<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->text('text', 4000)->nullable();
            $table->smallInteger('rating');
            $table->bigInteger('user_id');
            $table->foreignId('doctor_id')->constrained('doctors');
            $table->integer('status');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('doctor_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
