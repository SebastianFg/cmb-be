<?php

use App\Models\HealthInsurance;
use App\Models\Nationality;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CatalogSeeder::class]);
});

function staff(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

function asStaff(string $role = 'reception')
{
    return test()->actingAs(staff($role), 'sanctum');
}

/** El proyecto devuelve los errores de validación dentro de `data`. */
function assertInvalidField($response, string $field): void
{
    $response->assertUnprocessable();
    expect($response->json('data'))->toHaveKey($field);
}

function patientPayload(array $overrides = []): array
{
    return [
        'document_number' => '41222333',
        'first_name' => 'Elena',
        'last_name' => 'Quiroga',
        'birth_date' => '1990-04-12',
        'nationality_id' => Nationality::query()->where('name', 'Argentina')->value('id'),
        'phone' => '1154001122',
        'email' => 'elena.quiroga@correo.com',
        'health_insurance_id' => HealthInsurance::query()->where('name', 'IOMA')->value('id'),
        'member_number' => '7788-0011',
        ...$overrides,
    ];
}

describe('catálogos', function (): void {
    it('lista las nacionalidades ordenadas', function (): void {
        $response = asStaff()->getJson('/api/v1/nationalities')->assertOk();

        expect($response->json('data'))->toHaveCount(9);
        expect($response->json('data.0.name'))->toBe('Argentina');
    });

    it('lista las obras sociales e incluye IPS', function (): void {
        $response = asStaff()->getJson('/api/v1/health-insurances')->assertOk();

        expect(collect($response->json('data'))->pluck('name'))->toContain('IPS');
    });

    it('exige sesión', function (): void {
        test()->getJson('/api/v1/nationalities')->assertUnauthorized();
    });
});

describe('alta de pacientes', function (): void {
    it('registra la ficha y la devuelve con sus catálogos', function (): void {
        $response = asStaff()->postJson('/api/v1/patients', patientPayload())
            ->assertCreated()
            ->assertJsonPath('data.document_number', '41222333')
            ->assertJsonPath('data.nationality.name', 'Argentina')
            ->assertJsonPath('data.health_insurance.name', 'IOMA')
            ->assertJsonPath('data.has_portal_access', false);

        expect($response->json('data'))->not->toHaveKey('password');
        expect(Patient::query()->count())->toBe(1);
    });

    it('normaliza documento, teléfono y correo', function (): void {
        asStaff()->postJson('/api/v1/patients', patientPayload([
            'document_number' => '41.222.333',
            'phone' => '11 5400-1122',
            'email' => '  Elena.Quiroga@Correo.com  ',
        ]))->assertCreated();

        $patient = Patient::query()->sole();

        expect($patient->document_number)->toBe('41222333');
        expect($patient->phone)->toBe('1154001122');
        expect($patient->email)->toBe('elena.quiroga@correo.com');
    });

    it('acepta una ficha sin correo, como la que carga recepción por teléfono', function (): void {
        asStaff()->postJson('/api/v1/patients', patientPayload(['email' => null]))
            ->assertCreated()
            ->assertJsonPath('data.has_portal_access', false);
    });

    it('rechaza un documento repetido', function (): void {
        asStaff()->postJson('/api/v1/patients', patientPayload())->assertCreated();

        assertInvalidField(
            asStaff()->postJson('/api/v1/patients', patientPayload(['email' => 'otra@correo.com'])),
            'document_number',
        );
    });

    it('rechaza un correo que ya usa el personal', function (): void {
        $user = User::factory()->create(['email' => 'ocupado@cmbelgrano.com.ar']);

        assertInvalidField(
            asStaff()->postJson('/api/v1/patients', patientPayload(['email' => $user->email])),
            'email',
        );
    });

    it('valida el resto de los campos', function (string $field, array $payload): void {
        assertInvalidField(
            asStaff()->postJson('/api/v1/patients', patientPayload($payload)),
            $field,
        );
    })->with([
        'documento corto' => ['document_number', ['document_number' => '123']],
        'nombre de una letra' => ['first_name', ['first_name' => 'A']],
        'fecha futura' => ['birth_date', ['birth_date' => '2100-01-01']],
        'teléfono de 8 dígitos' => ['phone', ['phone' => '11540011']],
        'afiliado con formato inválido' => ['member_number', ['member_number' => 'ab']],
        'nacionalidad inexistente' => ['nationality_id', ['nationality_id' => 9999]],
        'cobertura inexistente' => ['health_insurance_id', ['health_insurance_id' => 9999]],
    ]);
});

describe('listado de pacientes', function (): void {
    beforeEach(function (): void {
        $ioma = HealthInsurance::query()->where('name', 'IOMA')->value('id');
        $pami = HealthInsurance::query()->where('name', 'PAMI')->value('id');

        Patient::factory()->create(['last_name' => 'Ferreyra', 'first_name' => 'Marta', 'document_number' => '30114552', 'health_insurance_id' => $ioma, 'password' => 'secreto']);
        Patient::factory()->create(['last_name' => 'Sosa', 'first_name' => 'Julián', 'document_number' => '28999111', 'health_insurance_id' => $pami, 'password' => null, 'blocked_until' => now()->addDays(5)]);
        Patient::factory()->create(['last_name' => 'Álvarez', 'first_name' => 'Nora', 'document_number' => '35222444', 'health_insurance_id' => $ioma, 'password' => null]);
    });

    it('ordena por apellido y pagina de a diez', function (): void {
        $response = asStaff()->getJson('/api/v1/patients')->assertOk();

        expect($response->json('data.0.last_name'))->toBe('Álvarez');
        expect($response->json('pagination.per_page'))->toBe(10);
        expect($response->json('pagination.total'))->toBe(3);
    });

    it('busca por documento o apellido', function (string $search, string $expected): void {
        $response = asStaff()->getJson("/api/v1/patients?search={$search}")->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.last_name'))->toBe($expected);
    })->with([
        'por documento' => ['30114552', 'Ferreyra'],
        'por apellido' => ['sosa', 'Sosa'],
    ]);

    it('filtra por cobertura', function (): void {
        $pami = HealthInsurance::query()->where('name', 'PAMI')->value('id');

        $response = asStaff()->getJson("/api/v1/patients?health_insurance_id={$pami}")->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.last_name'))->toBe('Sosa');
    });

    it('filtra por acceso al portal', function (): void {
        expect(asStaff()->getJson('/api/v1/patients?portal=active')->json('data'))->toHaveCount(1);
        expect(asStaff()->getJson('/api/v1/patients?portal=none')->json('data'))->toHaveCount(2);
    });

    it('filtra por penalización activa', function (): void {
        $penalized = asStaff()->getJson('/api/v1/patients?penalty=yes')->json('data');

        expect($penalized)->toHaveCount(1);
        expect($penalized[0]['last_name'])->toBe('Sosa');
        expect(asStaff()->getJson('/api/v1/patients?penalty=no')->json('data'))->toHaveCount(2);
    });
});

describe('control de acceso', function (): void {
    it('deja entrar a admin y recepción', function (string $role): void {
        asStaff($role)->getJson('/api/v1/patients')->assertOk();
    })->with(['admin', 'reception']);

    it('rechaza al profesional', function (): void {
        asStaff('professional')->getJson('/api/v1/patients')->assertForbidden();
    });

    it('rechaza al paciente', function (): void {
        $patient = Patient::factory()->create();

        test()->actingAs($patient, 'sanctum')
            ->getJson('/api/v1/patients')
            ->assertForbidden();
    });

    it('rechaza sin sesión', function (): void {
        test()->getJson('/api/v1/patients')->assertUnauthorized();
        test()->postJson('/api/v1/patients', patientPayload())->assertUnauthorized();
    });
});
