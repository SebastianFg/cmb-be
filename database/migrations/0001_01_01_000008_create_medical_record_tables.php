<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained();
            $table->string('blood_type')->nullable();
            $table->text('background')->nullable();
            $table->timestampTz('opened_at');
            $table->timestamps();
        });

        Schema::create('medical_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained();
            $table->foreignId('professional_id')->constrained();
            $table->string('reason')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('observations')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestamps();

            $table->index(['medical_record_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_entries');
        Schema::dropIfExists('medical_records');
    }
};
