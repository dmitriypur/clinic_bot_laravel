<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onec_slots', function (Blueprint $table) {
            $table->uuid('booking_uuid')->nullable()->after('external_slot_id');
            $table->index('booking_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('onec_slots', function (Blueprint $table) {
            $table->dropIndex(['booking_uuid']);
            $table->dropColumn('booking_uuid');
        });
    }
};
