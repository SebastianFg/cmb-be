<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'patient_id',
        'professional_id',
        'specialty_id',
        'room_id',
        'created_by',
        'cancelled_by',
        'starts_at',
        'ends_at',
        'status',
        'source',
        'confirmation_code',
        'cancellation_reason',
        'is_penalized',
        'notes',
        'attended_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'booked',
        'is_penalized' => false,
    ];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Professional, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasOne<MedicalEntry, $this> */
    public function medicalEntry(): HasOne
    {
        return $this->hasOne(MedicalEntry::class);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'professional_id' => 'integer',
            'specialty_id' => 'integer',
            'room_id' => 'integer',
            'created_by' => 'integer',
            'cancelled_by' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'is_penalized' => 'boolean',
            'attended_at' => 'datetime',
        ];
    }
}
