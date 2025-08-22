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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('last_name', 255);
            $table->string('first_name', 255);
            $table->string('second_name', 255)->nullable();
            $table->integer('experience');
            $table->integer('age');
            $table->string('photo_src')->nullable();
            $table->string('diploma_src')->nullable();
            $table->integer('status');
            $table->integer('age_admission_from');
            $table->integer('age_admission_to');
            $table->integer('sum_ratings')->default(0);
            $table->integer('count_ratings')->default(0);
            $table->uuid('uuid')->unique();
            $table->string('review_link', 500)->nullable();
            $table->timestamps();
            
            $table->index('status');
            $table->index('uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
