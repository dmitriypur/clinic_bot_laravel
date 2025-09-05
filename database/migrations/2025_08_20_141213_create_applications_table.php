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
        if (!Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id(); // Это автоматически создает bigInteger('id')->primary()->autoIncrement()
                $table->foreignId('city_id')->constrained('cities');
                $table->foreignId('clinic_id')->nullable()->constrained('clinics')->onDelete('cascade');
                $table->foreignId('doctor_id')->nullable()->constrained('doctors');
                $table->string('full_name_parent', 255)->nullable();
                $table->string('full_name', 255);
                $table->string('birth_date', 15)->nullable();
                $table->string('phone', 25);
                $table->string('promo_code', 100)->nullable();
                $table->bigInteger('tg_user_id')->nullable();
                $table->bigInteger('tg_chat_id')->nullable();
                $table->boolean('send_to_1c')->default(false);
                $table->timestamps();
                
                $table->index('city_id');
                $table->index('clinic_id');
                $table->index('doctor_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
