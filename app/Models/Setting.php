<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'center_name',
        'timezone',
        'cancellation_window_minutes',
        'cancellation_penalty_days',
        'reminder_lead_minutes',
        'no_show_alert_threshold',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'America/Argentina/Buenos_Aires',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cancellation_window_minutes' => 'integer',
            'cancellation_penalty_days' => 'integer',
            'reminder_lead_minutes' => 'integer',
            'no_show_alert_threshold' => 'integer',
        ];
    }
}
