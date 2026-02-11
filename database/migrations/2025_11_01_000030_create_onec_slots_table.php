<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onec_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('status', 20)->default('free');
            $table->string('external_slot_id', 191);
            $table->string('payload_hash', 64)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'external_slot_id'], 'onec_slots_unique_external');
            $table->index(['clinic_id', 'start_at']);
            $table->index(['doctor_id', 'start_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onec_slots');
    }
};
