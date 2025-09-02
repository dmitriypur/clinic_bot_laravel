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
        Schema::create('doctor_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->onDelete('cascade');
            $table->foreignId('cabinet_id')->constrained('cabinets')->onDelete('cascade');

            $table->date('date');

            $table->timestampTz('start_time');
            $table->timestampTz('end_time');

            $table->integer('slot_duration')->default(30);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabinet_id', 'date'], 'idx_cabinet_date');
            $table->index(['doctor_id', 'date'], 'idx_doctor_date');
            $table->unique(['doctor_id', 'cabinet_id', 'date', 'start_time', 'end_time'], 'unique_doctor_shift');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_shifts');
    }
};
