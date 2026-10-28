<?php

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['date', 'name', 'is_nationwide'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_nationwide' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_nationwide' => 'boolean',
        ];
    }
}
