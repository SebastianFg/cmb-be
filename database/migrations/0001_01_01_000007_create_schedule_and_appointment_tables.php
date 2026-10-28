<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained();
            $table->foreignId('specialty_id')->constrained();
            $table->foreignId('room_id')->constrained();
            $table->smallInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->smallInteger('slot_duration_minutes');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_weekday_check CHECK (weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_time_range_check CHECK (start_time < end_time)');
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_validity_range_check CHECK (valid_until IS NULL OR valid_from <= valid_until)');
        DB::statement(<<<'SQL'
            ALTER TABLE schedules
            ADD CONSTRAINT schedules_professional_exclusion
            EXCLUDE USING gist (
                professional_id WITH =,
                weekday WITH =,
                daterange(valid_from, COALESCE(valid_until, 'infinity'::date), '[]') WITH &&,
                int8range(EXTRACT(EPOCH FROM start_time)::bigint, EXTRACT(EPOCH FROM end_time)::bigint, '[)') WITH &&
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE schedules
            ADD CONSTRAINT schedules_room_exclusion
            EXCLUDE USING gist (
                room_id WITH =,
                weekday WITH =,
                daterange(valid_from, COALESCE(valid_until, 'infinity'::date), '[]') WITH &&,
                int8range(EXTRACT(EPOCH FROM start_time)::bigint, EXTRACT(EPOCH FROM end_time)::bigint, '[)') WITH &&
            )
            SQL);

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('type', ['absence', 'block']);
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_time_range_check CHECK (starts_at < ends_at)');

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('professional_id')->constrained();
            $table->foreignId('specialty_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('status', [
                'booked',
                'confirmed',
                'cancelled_by_professional',
                'cancelled_by_center',
                'no_show',
                'attended',
            ])->default('booked');
            $table->enum('source', ['portal', 'desk']);
            $table->string('confirmation_code')->unique();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('is_penalized')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('attended_at')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_time_range_check CHECK (starts_at < ends_at)');

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_professional_exclusion
            EXCLUDE USING gist (
                professional_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status NOT IN ('cancelled_by_professional', 'cancelled_by_center'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_patient_exclusion
            EXCLUDE USING gist (
                patient_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status NOT IN ('cancelled_by_professional', 'cancelled_by_center'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_room_exclusion
            EXCLUDE USING gist (
                room_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status NOT IN ('cancelled_by_professional', 'cancelled_by_center'))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE IF EXISTS appointments DROP CONSTRAINT IF EXISTS appointments_room_exclusion');
        DB::statement('ALTER TABLE IF EXISTS appointments DROP CONSTRAINT IF EXISTS appointments_patient_exclusion');
        DB::statement('ALTER TABLE IF EXISTS appointments DROP CONSTRAINT IF EXISTS appointments_professional_exclusion');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('schedule_exceptions');
        DB::statement('ALTER TABLE IF EXISTS schedules DROP CONSTRAINT IF EXISTS schedules_room_exclusion');
        DB::statement('ALTER TABLE IF EXISTS schedules DROP CONSTRAINT IF EXISTS schedules_professional_exclusion');
        Schema::dropIfExists('schedules');
    }
};
