<?php

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\RoleSeeder;

const SEEDED_PASSWORD = 'Belgrano#2026$Seg';

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, DemoAccountSeeder::class]);
});

it('crea el catálogo completo de roles', function (): void {
    expect(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'patient', 'professional', 'reception']);
});

it('crea una cuenta de personal por rol', function (string $role): void {
    $user = User::query()->where('email', "{$role}@example.com")->sole();

    expect($user->is_active)->toBeTrue();
    expect($user->roles->pluck('name')->all())->toBe([$role]);
})->with(['admin', 'reception', 'professional']);

it('crea la cuenta del paciente', function (): void {
    $patient = Patient::query()->where('email', 'patient@example.com')->sole();

    expect($patient->is_active)->toBeTrue();
    expect($patient->document_number)->toBe('40000001');
});

it('deja las cuatro cuentas en condiciones de iniciar sesión', function (): void {
    foreach (['admin', 'reception', 'professional'] as $role) {
        test()->postJson('/api/v1/auth/users/login', [
            'email' => "{$role}@example.com",
            'password' => SEEDED_PASSWORD,
            'device_name' => 'seeder-test',
        ])->assertOk()->assertJsonPath('data.roles', [$role]);
    }

    test()->postJson('/api/v1/auth/patients/login', [
        'email' => 'patient@example.com',
        'password' => SEEDED_PASSWORD,
        'device_name' => 'seeder-test',
    ])->assertOk()
        ->assertJsonPath('actor_type', 'patients')
        ->assertJsonPath('data.roles', ['patient']);
});

it('se puede volver a correr sin duplicar cuentas', function (): void {
    $this->seed([RoleSeeder::class, DemoAccountSeeder::class]);

    expect(User::query()->count())->toBe(3);
    expect(Patient::query()->count())->toBe(1);
    expect(Role::query()->count())->toBe(4);
});
