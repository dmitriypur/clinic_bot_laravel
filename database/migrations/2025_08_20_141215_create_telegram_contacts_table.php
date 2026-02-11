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
        Schema::create('telegram_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_user_id')->index();
            $table->unsignedBigInteger('tg_chat_id')->nullable()->index();
            $table->string('phone', 32);
            $table->timestamps();

            $table->unique('tg_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_contacts');
    }
};
