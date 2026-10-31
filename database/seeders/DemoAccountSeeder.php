<?php

namespace Database\Seeders;

use App\Models\HealthInsurance;
use App\Models\Nationality;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    /**
     * Contraseña de las cuentas de prueba. Se puede reemplazar con
     * SEED_ACCOUNT_PASSWORD; no debe usarse este valor en producción.
     */
    private const DEFAULT_PASSWORD = 'Belgrano2026#';

    /**
     * Una cuenta de personal por rol.
     *
     * @var list<array{role: string, name: string, email: string}>
     */
    private const STAFF = [
        ['role' => 'admin', 'name' => 'Admin del Centro', 'email' => 'admin@example.com'],
        ['role' => 'reception', 'name' => 'Recepcion Belgrano', 'email' => 'reception@example.com'],
        ['role' => 'professional', 'name' => 'Profesional Belgrano', 'email' => 'professional@example.com'],
    ];

    private const PATIENT_EMAIL = 'patient@example.com';

    public function run(): void
    {
        $hashedPassword = Hash::make($this->password());

        foreach (self::STAFF as $account) {
            $role = Role::query()->where('name', $account['role'])->sole();

            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $hashedPassword,
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );

            $user->roles()->sync([$role->id]);
        }

        $this->seedPatient($hashedPassword);

        $this->command?->info('Cuentas de prueba listas. Contraseña: '.$this->password());
    }

    private function seedPatient(string $hashedPassword): void
    {
        $nationality = Nationality::query()->updateOrCreate(
            ['name' => 'Argentina'],
            ['iso_code' => 'AR'],
        );

        $insurance = HealthInsurance::query()->firstOrCreate(['name' => 'Particular']);

        Patient::query()->updateOrCreate(
            ['email' => self::PATIENT_EMAIL],
            [
                'health_insurance_id' => $insurance->id,
                'nationality_id' => $nationality->id,
                'document_number' => '40000001',
                'first_name' => 'Paciente',
                'last_name' => 'De Prueba',
                'birth_date' => '1990-01-01',
                'phone' => '1140000001',
                'password' => $hashedPassword,
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );
    }

    private function password(): string
    {
        return (string) env('SEED_ACCOUNT_PASSWORD', self::DEFAULT_PASSWORD);
    }
}
