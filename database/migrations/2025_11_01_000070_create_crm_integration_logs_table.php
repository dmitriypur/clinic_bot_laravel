<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_integration_logs')) {
            Schema::create('crm_integration_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('application_id')->nullable()->index();
                $table->string('provider', 50);
                $table->string('status', 20);
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedTinyInteger('attempt')->default(1);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_integration_logs');
    }
};
