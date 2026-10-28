<?php

namespace App\Models;

use Database\Factories\NationalityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nationality extends Model
{
    /** @use HasFactory<NationalityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'iso_code'];

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
