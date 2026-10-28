<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password'])]
class Patient extends Authenticatable
{
    /** @use HasFactory<PatientFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'health_insurance_id',
        'nationality_id',
        'document_number',
        'first_name',
        'last_name',
        'birth_date',
        'phone',
        'email',
        'password',
        'google_id',
        'email_verified_at',
        'member_number',
        'blocked_until',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return BelongsTo<HealthInsurance, $this> */
    public function healthInsurance(): BelongsTo
    {
        return $this->belongsTo(HealthInsurance::class);
    }

    /** @return BelongsTo<Nationality, $this> */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class);
    }

    /** @return HasOne<MedicalRecord, $this> */
    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return MorphMany<Notification, $this> */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    /** @return MorphMany<NotificationPreference, $this> */
    public function notificationPreferences(): MorphMany
    {
        return $this->morphMany(NotificationPreference::class, 'notifiable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'health_insurance_id' => 'integer',
            'nationality_id' => 'integer',
            'birth_date' => 'date',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'blocked_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
