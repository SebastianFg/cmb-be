<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Catálogo de roles. Los tres primeros se asignan por `role_user`; el de
     * paciente existe para que el catálogo esté completo y coincida con el
     * frontend, pero no se asigna ahí: el paciente es otro actor autenticable,
     * con su propia tabla.
     *
     * @var list<array{name: string, description: string}>
     */
    private const ROLES = [
        ['name' => 'admin', 'description' => 'Administrador del centro'],
        ['name' => 'reception', 'description' => 'Recepción'],
        ['name' => 'professional', 'description' => 'Profesional de la salud'],
        ['name' => 'patient', 'description' => 'Paciente'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description'], 'is_system' => true],
            );
        }
    }
}
