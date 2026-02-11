<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('status')->index();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('slot_duration')->index();
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('name')->index();
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
