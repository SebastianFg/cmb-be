<?php

namespace App\Models;

use Database\Factories\MedicalEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalEntry extends Model
{
    /** @use HasFactory<MedicalEntryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'medical_record_id',
        'appointment_id',
        'professional_id',
        'reason',
        'diagnosis',
        'treatment',
        'observations',
        'recorded_at',
    ];

    /** @return BelongsTo<MedicalRecord, $this> */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Professional, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'medical_record_id' => 'integer',
            'appointment_id' => 'integer',
            'professional_id' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }
}
