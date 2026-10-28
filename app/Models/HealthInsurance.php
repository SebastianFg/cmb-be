<?php

namespace App\Models;

use Database\Factories\HealthInsuranceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthInsurance extends Model
{
    /** @use HasFactory<HealthInsuranceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'plan'];

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
