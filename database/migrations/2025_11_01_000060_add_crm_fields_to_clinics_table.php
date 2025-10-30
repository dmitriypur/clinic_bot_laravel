<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('crm_provider', 50)
                ->default('none')
                ->after('slot_duration');
            $table->json('crm_settings')
                ->nullable()
                ->after('crm_provider');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['crm_provider', 'crm_settings']);
        });
    }
};
