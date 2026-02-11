<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('local_type', 50);
            $table->unsignedBigInteger('local_id');
            $table->string('external_id', 191);
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'local_type', 'local_id'], 'external_mappings_local_unique');
            $table->index(['clinic_id', 'local_type', 'external_id'], 'external_mappings_external_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_mappings');
    }
};
