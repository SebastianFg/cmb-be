<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('license_number');
            $table->timestamps();
        });

        Schema::create('professional_specialty', function (Blueprint $table) {
            $table->foreignId('professional_id')->constrained();
            $table->foreignId('specialty_id')->constrained();
            $table->primary(['professional_id', 'specialty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_specialty');
        Schema::dropIfExists('professionals');
    }
};
