<?php

namespace App\Models;

use App\Enums\ExceptionType;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['professional_id', 'starts_at', 'ends_at', 'type', 'reason'];

    /** @return BelongsTo<Professional, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'professional_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'type' => ExceptionType::class,
        ];
    }
}
