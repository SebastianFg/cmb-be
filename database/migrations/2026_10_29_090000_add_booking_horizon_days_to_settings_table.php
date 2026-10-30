<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horizonte de reserva (RF-15): cuántos días hacia adelante se puede
     * sacar turno. El prototipo ya lo trae en `data/settings.json`.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->integer('booking_horizon_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('booking_horizon_days');
        });
    }
};
