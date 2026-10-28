<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\PatientAuthResource;
use App\Http\Resources\UserAuthResource;
use App\Models\Patient;
use App\Models\User;
use App\Services\ResponseJson;
use App\Services\StatusCode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthenticatableModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Mismo mensaje para cuenta inexistente, contraseña incorrecta, cuenta
     * inactiva y cuenta sin contraseña: la respuesta no revela qué correos
     * existen ni en qué estado están.
     */
    private const INVALID_CREDENTIALS = 'Las credenciales no son correctas.';

    private const ACTOR_USERS = 'users';

    private const ACTOR_PATIENTS = 'patients';

    public function loginUser(LoginRequest $request): JsonResponse
    {
        return $this->login($request, self::ACTOR_USERS);
    }

    public function loginPatient(LoginRequest $request): JsonResponse
    {
        return $this->login($request, self::ACTOR_PATIENTS);
    }

    /**
     * Identifica al actor de la sesión en curso.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->user();
        $actorType = $actor instanceof User ? self::ACTOR_USERS : self::ACTOR_PATIENTS;

        return ResponseJson::success(
            'Sesión vigente.',
            $this->resourceFor($actor, $actorType),
            StatusCode::HTTP_OK,
            ['actor_type' => $actorType],
        );
    }

    /**
     * Revoca únicamente el token con el que llegó la petición: las sesiones
     * abiertas en otros dispositivos siguen vivas.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ResponseJson::success('Sesión cerrada.', null);
    }

    private function login(LoginRequest $request, string $actorType): JsonResponse
    {
        $credentials = $request->validated();
        $actor = $this->findActor($actorType, $credentials['email']);

        if (! $this->canSignIn($actor, $credentials['password'])) {
            return ResponseJson::unauthenticate(self::INVALID_CREDENTIALS);
        }

        // Un token vigente por dispositivo: el anterior del mismo equipo se cae.
        $actor->tokens()->where('name', $credentials['device_name'])->delete();

        // La expiración se escribe en el token y no sólo en la configuración
        // global, para que `sanctum:prune-expired` encuentre qué purgar.
        $token = $actor->createToken(
            $credentials['device_name'],
            [$this->abilityFor($actorType)],
            now()->addMinutes($this->expirationMinutes()),
        )->plainTextToken;

        return ResponseJson::success(
            'Sesión iniciada.',
            $this->resourceFor($actor, $actorType),
            StatusCode::HTTP_OK,
            [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $this->expirationMinutes() * 60,
                'actor_type' => $actorType,
            ],
        );
    }

    private function findActor(string $actorType, string $email): ?AuthenticatableModel
    {
        return $actorType === self::ACTOR_USERS
            ? User::query()->where('email', $email)->first()
            : Patient::query()->where('email', $email)->first();
    }

    /**
     * La cuenta tiene que existir, estar activa y tener contraseña propia.
     * No se exige correo verificado.
     */
    private function canSignIn(?AuthenticatableModel $actor, string $password): bool
    {
        if (! $actor instanceof AuthenticatableModel || ! $actor->is_active) {
            return false;
        }

        if (blank($actor->password)) {
            return false;
        }

        return Hash::check($password, $actor->password);
    }

    private function expirationMinutes(): int
    {
        return (int) config('sanctum.expiration');
    }

    private function abilityFor(string $actorType): string
    {
        return $actorType === self::ACTOR_USERS ? 'user' : 'patient';
    }

    private function resourceFor(Authenticatable $actor, string $actorType): JsonResource
    {
        if ($actorType === self::ACTOR_USERS) {
            /** @var User $actor */
            return new UserAuthResource($actor->load('roles'));
        }

        /** @var Patient $actor */
        return new PatientAuthResource($actor);
    }
}
