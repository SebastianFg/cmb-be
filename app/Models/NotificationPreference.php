<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'channel',
        'is_enabled',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_enabled' => true];

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notifiable_id' => 'integer',
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'is_enabled' => 'boolean',
        ];
    }
}
