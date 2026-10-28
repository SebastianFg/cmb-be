<?php

namespace App\Models;

use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalRecord extends Model
{
    /** @use HasFactory<MedicalRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['patient_id', 'blood_type', 'background', 'opened_at'];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<MedicalEntry, $this> */
    public function medicalEntries(): HasMany
    {
        return $this->hasMany(MedicalEntry::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'opened_at' => 'datetime',
        ];
    }
}
