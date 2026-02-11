<?php

use App\Enums\IntegrationMode;
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
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('integration_mode')
                ->default(IntegrationMode::LOCAL->value)
                ->after('slot_duration');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('integration_mode')
                ->nullable()
                ->after('slot_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('integration_mode');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('integration_mode');
        });
    }
};
