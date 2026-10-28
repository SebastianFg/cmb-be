<?php

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

const DEVICE = 'navegador-recepcion';
const PASSWORD = 'turnos2026';

beforeEach(function (): void {
    RateLimiter::clear('users|admin@cmbelgrano.com.ar|127.0.0.1');
    RateLimiter::clear('patients|paciente@correo.com|127.0.0.1');
});

function makeUser(array $attributes = []): User
{
    return User::factory()->create([
        'email' => 'admin@cmbelgrano.com.ar',
        'password' => Hash::make(PASSWORD),
        ...$attributes,
    ]);
}

function makePatient(array $attributes = []): Patient
{
    return Patient::factory()->create([
        'email' => 'paciente@correo.com',
        'password' => Hash::make(PASSWORD),
        ...$attributes,
    ]);
}

function login(string $path, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/auth/{$path}/login", [
        'email' => $path === 'users' ? 'admin@cmbelgrano.com.ar' : 'paciente@correo.com',
        'password' => PASSWORD,
        'device_name' => DEVICE,
        ...$payload,
    ]);
}

describe('login correcto', function (): void {
    it('abre la sesión de un usuario del centro', function (): void {
        $user = makeUser();
        $user->roles()->attach(Role::factory()->create(['name' => 'reception']));

        $response = login('users');

        $response->assertOk()
            ->assertJsonPath('message', 'Sesión iniciada.')
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 86400)
            ->assertJsonPath('actor_type', 'users')
            ->assertJsonPath('data.email', 'admin@cmbelgrano.com.ar')
            ->assertJsonPath('data.roles', ['reception'])
            ->assertJsonStructure(['access_token']);

        expect($response->json('access_token'))->toBeString()->not->toBeEmpty();
    });

    it('abre la sesión de un paciente', function (): void {
        makePatient();

        login('patients')
            ->assertOk()
            ->assertJsonPath('actor_type', 'patients')
            ->assertJsonPath('data.email', 'paciente@correo.com')
            ->assertJsonPath('data.roles', ['patient']);
    });

    it('normaliza el correo antes de buscar la cuenta', function (): void {
        makeUser();

        login('users', ['email' => '  Admin@CMBelgrano.com.AR  '])->assertOk();
    });

    it('no expone la contraseña en la respuesta', function (): void {
        makeUser();

        $response = login('users');

        expect($response->json('data'))->not->toHaveKey('password');
    });
});

describe('credenciales rechazadas', function (): void {
    it('rechaza una contraseña incorrecta', function (): void {
        makeUser();

        login('users', ['password' => 'otra-cosa'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Las credenciales no son correctas.');
    });

    it('rechaza una cuenta inexistente', function (): void {
        login('users')->assertUnauthorized();
    });

    it('rechaza una cuenta inactiva', function (): void {
        makeUser(['is_active' => false]);

        login('users')->assertUnauthorized();
    });

    it('rechaza una cuenta sin contraseña', function (): void {
        User::factory()->withoutPassword()->create(['email' => 'admin@cmbelgrano.com.ar']);

        login('users')->assertUnauthorized();
    });

    it('responde lo mismo ante cualquier motivo de fallo', function (): void {
        $messages = [];

        $messages[] = login('users')->json('message');

        makeUser(['is_active' => false]);
        $messages[] = login('users')->json('message');

        User::query()->delete();
        User::factory()->withoutPassword()->create(['email' => 'admin@cmbelgrano.com.ar']);
        $messages[] = login('users')->json('message');

        User::query()->delete();
        makeUser();
        $messages[] = login('users', ['password' => 'otra-cosa'])->json('message');

        expect(array_unique($messages))->toBe(['Las credenciales no son correctas.']);
    });

    it('permite una cuenta con el correo sin verificar', function (): void {
        User::factory()->unverified()->create([
            'email' => 'admin@cmbelgrano.com.ar',
            'password' => Hash::make(PASSWORD),
        ]);

        login('users')->assertOk();
    });

    it('exige email, contraseña y dispositivo', function (): void {
        test()->postJson('/api/v1/auth/users/login', [])
            ->assertUnprocessable();
    });

    it('rechaza un nombre de dispositivo de más de 100 caracteres', function (): void {
        makeUser();

        login('users', ['device_name' => str_repeat('a', 101)])
            ->assertUnprocessable();
    });
});

describe('tokens por dispositivo', function (): void {
    it('reemplaza el token anterior del mismo dispositivo', function (): void {
        $user = makeUser();

        $first = login('users')->json('access_token');
        $second = login('users')->json('access_token');

        expect($user->tokens()->count())->toBe(1);
        expect(PersonalAccessToken::findToken($first))->toBeNull();
        expect(PersonalAccessToken::findToken($second))->not->toBeNull();
    });

    it('mantiene tokens simultáneos para dispositivos distintos', function (): void {
        $user = makeUser();

        $desk = login('users')->json('access_token');
        $mobile = login('users', ['device_name' => 'telefono-guardia'])->json('access_token');

        expect($user->tokens()->count())->toBe(2);
        expect(PersonalAccessToken::findToken($desk))->not->toBeNull();
        expect(PersonalAccessToken::findToken($mobile))->not->toBeNull();
    });

    it('emite la ability que corresponde a cada actor', function (): void {
        makeUser();
        makePatient();

        $userToken = PersonalAccessToken::findToken(login('users')->json('access_token'));
        $patientToken = PersonalAccessToken::findToken(login('patients')->json('access_token'));

        expect($userToken->abilities)->toBe(['user']);
        expect($patientToken->abilities)->toBe(['patient']);
    });

    it('vence el token al día', function (): void {
        makeUser();

        $token = PersonalAccessToken::findToken(login('users')->json('access_token'));

        expect(abs($token->expires_at->diffInMinutes(now())))->toEqualWithDelta(1440, 1);
    });
});

describe('me', function (): void {
    it('identifica a un usuario autenticado', function (): void {
        makeUser();
        $token = login('users')->json('access_token');

        test()->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('actor_type', 'users')
            ->assertJsonPath('data.email', 'admin@cmbelgrano.com.ar');
    });

    it('identifica a un paciente autenticado', function (): void {
        makePatient();
        $token = login('patients')->json('access_token');

        test()->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('actor_type', 'patients')
            ->assertJsonPath('data.document_number', Patient::first()->document_number);
    });

    it('responde 401 sin autenticar', function (): void {
        test()->getJson('/api/v1/auth/me')->assertUnauthorized();
    });
});

describe('logout', function (): void {
    it('revoca sólo el token en uso', function (): void {
        $user = makeUser();

        $desk = login('users')->json('access_token');
        $mobile = login('users', ['device_name' => 'telefono-guardia'])->json('access_token');

        test()->withToken($desk)->postJson('/api/v1/auth/logout')->assertOk();

        expect($user->tokens()->count())->toBe(1);
        expect(PersonalAccessToken::findToken($desk))->toBeNull();
        expect(PersonalAccessToken::findToken($mobile))->not->toBeNull();
    });

    it('responde 401 sin autenticar', function (): void {
        test()->postJson('/api/v1/auth/logout')->assertUnauthorized();
    });
});

describe('rate limiting', function (): void {
    it('bloquea a partir del sexto intento', function (): void {
        makeUser();

        foreach (range(1, 5) as $attempt) {
            login('users', ['password' => 'incorrecta'])->assertUnauthorized();
        }

        login('users', ['password' => 'incorrecta'])->assertStatus(429);
    });

    it('no bloquea al otro tipo de actor', function (): void {
        makeUser();
        makePatient();

        foreach (range(1, 6) as $attempt) {
            login('users', ['password' => 'incorrecta']);
        }

        login('patients')->assertOk();
    });
});
