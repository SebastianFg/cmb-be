<?php

namespace App\Models;

use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'professional_id',
        'specialty_id',
        'room_id',
        'weekday',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'valid_from',
        'valid_until',
    ];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'professional_id' => 'integer',
            'specialty_id' => 'integer',
            'room_id' => 'integer',
            'weekday' => 'integer',
            'slot_duration_minutes' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
