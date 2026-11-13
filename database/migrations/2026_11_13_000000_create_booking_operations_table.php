<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_operations', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64);
            $table->uuid('operation_key');
            $table->unique(['client_id', 'operation_key']);
            $table->uuid('onec_request_id')->unique();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('doctor_id');
            $table->unsignedBigInteger('cabinet_id')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16);
            $table->string('result_code', 32)->nullable();
            $table->unsignedSmallInteger('result_http_status')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->timestamps();
            $table->index(['doctor_id', 'status', 'starts_at']);
            $table->index(['cabinet_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_operations');
    }
};
