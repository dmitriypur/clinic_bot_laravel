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
        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'external_appointment_id')) {
                $table->string('external_appointment_id', 191)->nullable()->after('appointment_datetime');
                $table->index('external_appointment_id', 'applications_external_appointment_id_index');
            }

            if (! Schema::hasColumn('applications', 'integration_type')) {
                $table->string('integration_type', 50)->nullable()->after('external_appointment_id');
                $table->index('integration_type', 'applications_integration_type_index');
            }

            if (! Schema::hasColumn('applications', 'integration_status')) {
                $table->string('integration_status', 50)->nullable()->after('integration_type');
                $table->index('integration_status', 'applications_integration_status_index');
            }

            if (! Schema::hasColumn('applications', 'integration_payload')) {
                $table->json('integration_payload')->nullable()->after('integration_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'integration_payload')) {
                $table->dropColumn('integration_payload');
            }

            if (Schema::hasColumn('applications', 'integration_status')) {
                $table->dropIndex('applications_integration_status_index');
                $table->dropColumn('integration_status');
            }

            if (Schema::hasColumn('applications', 'integration_type')) {
                $table->dropIndex('applications_integration_type_index');
                $table->dropColumn('integration_type');
            }

            if (Schema::hasColumn('applications', 'external_appointment_id')) {
                $table->dropIndex('applications_external_appointment_id_index');
                $table->dropColumn('external_appointment_id');
            }
        });
    }
};
