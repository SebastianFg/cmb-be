<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El motivo que declara el paciente al reservar y la observación
     * administrativa del turno (RF-21) son datos distintos, con autor y
     * momento distintos: el prototipo los separa y `notes` sola no alcanza.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('reason', 200)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
