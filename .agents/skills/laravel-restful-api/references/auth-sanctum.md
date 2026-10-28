# Referencia: Autenticación con Laravel Sanctum

## Instalación

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

## Configuración en User model

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

## Rutas de autenticación

```php
// routes/api.php
Route::prefix('v1/auth')->group(function () {
    Route::post('login',    [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout',  [AuthController::class, 'logout']);
        Route::get('me',       [AuthController::class, 'me']);
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('products', ProductController::class);
});
```

## AuthController

```php
class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            throw new AuthenticationException('Credenciales inválidas.');
        }

        $user  = Auth::user();
        $token = $user->createToken('api-token', ['*'])->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }
}
```

## Abilities (permisos por token)

```php
// Crear token con abilities específicos
$token = $user->createToken('mobile-app', ['products:read', 'orders:write'])->plainTextToken;

// Verificar ability en controller
if ($request->user()->tokenCan('products:write')) { ... }

// Middleware
Route::middleware(['auth:sanctum', 'abilities:products:write'])->group(...);
```

## Token expiration

```php
// config/sanctum.php
'expiration' => 525960, // minutos (1 año) — null para nunca
```

## Seguridad adicional

```php
// Revocar todos los tokens del usuario (logout de todos los dispositivos)
$user->tokens()->delete();

// Revocar tokens expirados (comando scheduleable)
$schedule->command('sanctum:prune-expired --hours=24')->daily();
```
