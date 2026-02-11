<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (! Schema::hasColumn('clinics', 'dashboard_calendar_enabled')) {
                $table->boolean('dashboard_calendar_enabled')
                    ->default(true)
                    ->after('crm_settings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            if (Schema::hasColumn('clinics', 'dashboard_calendar_enabled')) {
                $table->dropColumn('dashboard_calendar_enabled');
            }
        });
    }
};
