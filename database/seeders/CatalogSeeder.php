<?php

namespace Database\Seeders;

use App\Models\HealthInsurance;
use App\Models\Nationality;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * @var list<array{name: string, iso_code: string|null}>
     */
    private const NATIONALITIES = [
        ['name' => 'Argentina', 'iso_code' => 'AR'],
        ['name' => 'Boliviana', 'iso_code' => 'BO'],
        ['name' => 'Brasileña', 'iso_code' => 'BR'],
        ['name' => 'Chilena', 'iso_code' => 'CL'],
        ['name' => 'Paraguaya', 'iso_code' => 'PY'],
        ['name' => 'Peruana', 'iso_code' => 'PE'],
        ['name' => 'Uruguaya', 'iso_code' => 'UY'],
        ['name' => 'Venezolana', 'iso_code' => 'VE'],
        ['name' => 'Otra', 'iso_code' => null],
    ];

    /**
     * Coberturas que acepta el centro. «Particular» es la opción para quien no
     * tiene obra social, y por eso no pide número de afiliado.
     *
     * @var list<array{name: string, plan: string|null}>
     */
    private const HEALTH_INSURANCES = [
        ['name' => 'Particular', 'plan' => null],
        ['name' => 'OSDE', 'plan' => '210'],
        ['name' => 'OSDE', 'plan' => '310'],
        ['name' => 'Swiss Medical', 'plan' => 'SMG02'],
        ['name' => 'Swiss Medical', 'plan' => 'SMG20'],
        ['name' => 'Galeno', 'plan' => 'Azul'],
        ['name' => 'Galeno', 'plan' => 'Plata'],
        ['name' => 'IOMA', 'plan' => null],
        ['name' => 'PAMI', 'plan' => null],
        ['name' => 'IPS', 'plan' => null],
    ];

    public function run(): void
    {
        foreach (self::NATIONALITIES as $nationality) {
            Nationality::query()->updateOrCreate(
                ['name' => $nationality['name']],
                ['iso_code' => $nationality['iso_code']],
            );
        }

        foreach (self::HEALTH_INSURANCES as $insurance) {
            HealthInsurance::query()->firstOrCreate([
                'name' => $insurance['name'],
                'plan' => $insurance['plan'],
            ]);
        }
    }
}
