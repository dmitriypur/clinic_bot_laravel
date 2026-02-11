<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('external_appointment_id', 191)->nullable()->after('appointment_datetime');
            $table->string('integration_type', 50)->nullable()->after('external_appointment_id')->comment('Например: local, onec');
            $table->string('integration_status', 50)->nullable()->after('integration_type');
            $table->json('integration_payload')->nullable()->after('integration_status');

            $table->index('external_appointment_id');
            $table->index('integration_type');
            $table->index('integration_status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['external_appointment_id']);
            $table->dropIndex(['integration_type']);
            $table->dropIndex(['integration_status']);

            $table->dropColumn([
                'external_appointment_id',
                'integration_type',
                'integration_status',
                'integration_payload',
            ]);
        });
    }
};
