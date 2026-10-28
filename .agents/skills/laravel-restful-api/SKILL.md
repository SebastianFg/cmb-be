---
name: laravel-restful-api
description: >
    Guía experta para diseñar y construir APIs RESTful profesionales con Laravel 13 (última versión estable).
    Usa este skill siempre que el usuario quiera crear una API en Laravel, diseñar endpoints REST, trabajar con
    migraciones, modelos Eloquent, controladores API, resources/transformers, validación con FormRequest,
    manejo de errores HTTP o procesamiento masivo de datos. Activar también cuando el usuario mencione:
    "hacer una API", "crear endpoint", "Laravel API", "resource controller", "migración", "Eloquent",
    "JsonResource", "FormRequest", "apiResource", "rate limiting", "Sanctum", "autenticación API" o
    cualquier término relacionado con el desarrollo backend en Laravel.
---

# Laravel RESTful API — Guía Completa de Buenas Prácticas (Laravel 13.x)

> **Versión de referencia:** Laravel 13.x (PHP 8.3+).
> Para profundizar en cualquier sección, lee el archivo de referencia indicado en `references/`.

---

## Estructura de proyecto recomendada

```
├─ app/
│  ├─ Exceptions/
│  │  └─ Handler.php
│  ├─ Helpers/
│  │  └─ ApiResponse.php
│  ├─ Http/
│  │  ├─ Controllers/
│  │  │  └─ PersonnelController.php
│  │  ├─ Requests/
│  │  │  └─ StorePersonnelRequest.php
│  │  │  └─ UpdatePersonnelRequest.php
│  │  ├─ Resources/
│  │  │  └─ PersonnelResource.php
│  │  └─ Middleware/
│  ├─ Models/
│  │  └─ Personnel.php
│  └─ Services/
│     └─ PersonnelService.php
├─ bootstrap/
│  └─ app.php
├─ config/
│  ├─ auth.php
│  ├─ cors.php
│  ├─ keycloak.php
│  └─ logging.php
├─ database/
│  ├─ migrations/
│  └─ seeders/
├─ routes/
│  └─ api.php
├─ tests/
│  ├─ Feature/
│  └─ Unit/
└─ phpunit.xml

```

---

## 1. ApiResponse Helper

**Archivo:** `app/Helpers/ApiResponse.php`

Helper centralizado que garantiza formato consistente en todas las respuestas JSON de la API.

### Formato de respuesta

```json
{
  "status": 200,
  "message": "Success",
  "data": {...},
  "errors": {},
  "meta": {}
}
```

### Implementación

```php
<?php

namespace App\Helpers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public function success(?string $message = null, mixed $data = null): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse(200, $message, $data);
    }

    public function created(?string $message = null, mixed $data = null): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse(201, $message, $data);
    }

    public function notFound(?string $message = null, mixed $data = null): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse(404, $message, $data);
    }

    public function unprocessable(?string $message = null, array $errors = []): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse(422, $message, null, $errors);
    }

    public function unauthorized(?string $message = null, mixed $data = null): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse(401, $message, $data);
    }

    public function error(?string $message = null, int $statusCode = 500, mixed $data = null): \Illuminate\Http\JsonResponse
    {
        return $this->buildResponse($statusCode, $message, $data);
    }

    public function noContent(): \Illuminate\Http\JsonResponse
    {
        return response()->json(null, 204);
    }

    private function buildResponse(
        int $status,
        ?string $message,
        mixed $data = null,
        array $errors = []
    ): \Illuminate\Http\JsonResponse {
        $meta = [];

        // Detección automática de paginación
        if ($data instanceof LengthAwarePaginator) {
            $meta = [
                'total' => $data->total(),
                'per_page' => $data->perPage(),
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
            ];
            $data = $data->items();
        } elseif ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $paginator = $data->resource;
            $meta = [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve();
        }

        $response = [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ];

        return response()->json($response, $status);
    }
}
```

### Uso en controladores

Cuando el helper `ApiResponse` ya resuelve `JsonResource` y `ResourceCollection`, el controlador debe pasar el resource directamente. No hagas `->resolve()` manual ni transformes el paginador con `setCollection()` si ya vas a retornar `Resource::collection($paginator)`.

```php
$apiResponse = new ApiResponse();

// Éxito
return $apiResponse->success('Usuario encontrado', UserResource::make($user));

// Colección paginada transformada por ResourceCollection
return $apiResponse->success('Usuarios obtenidos', UserResource::collection($users));

// Creado
return $apiResponse->created('Usuario creado', UserResource::make($user));

// No encontrado
return $apiResponse->notFound('Usuario no encontrado');

// Error de validación
return $apiResponse->unprocessable('Error de validación', $errors);

// No autorizado
return $apiResponse->unauthorized('No autenticado');

// Error genérico
return $apiResponse->error('Error interno', 500);

// Sin contenido
return $apiResponse->noContent();
```

---

## 2. Migraciones

**Documentación oficial:** `https://laravel.com/docs/13.x/migrations`

### Reglas fundamentales

```php
// SIEMPRE up() con Schema::create + down() con Schema::dropIfExists
return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();        // índice unique
            $table->decimal('price', 10, 2);
            $table->foreignId('category_id')
                  ->constrained()                    // FK a categories.id
                  ->cascadeOnDelete();               // borrado en cascada
            $table->index(['category_id', 'name']); // índice compuesto
            $table->timestamps();
            $table->softDeletes();                   // deleted_at cuando aplique
        });
    }

    public function down(): void {
        Schema::dropIfExists('products');
    }
};
```

### Evolución del schema (sin tocar migraciones existentes)

```php
// Nueva migración para agregar/renombrar columnas
Schema::table('products', function (Blueprint $table) {
    $table->string('sku')->nullable()->after('name');
    $table->renameColumn('price', 'base_price');
    $table->dropColumn('legacy_field');
});
```

### Comandos esenciales

```bash
php artisan migrate                          # aplicar pendientes
php artisan migrate:rollback --step=2        # revertir N batches
php artisan optimize:clear                   # limpiar cache de config, routes, views
php artisan dump-autoload                    # regenerar autoload (si agregás clases manualmente)
php artisan migrate:fresh --seed             # reset total + seed (NO usar en producción)
```

### Manipulación de data con FKs activas

```php
Schema::withoutForeignKeyConstraints(function () {
    DB::table('products')->truncate();
});
```

### Reglas clave

- **Usá nombres de tabla como strings literales** — nunca `User::TABLE_NAME` (fragilidad en migraciones viejas).
- **Separar schema de datos**: cambios estructurales en migraciones, datos iniciales en seeders o Artisan commands.
- **En producción**: en lugar de `down()`, crear nueva migración que preserve datos.
- **UUIDs**: usar `$table->uuid('id')->primary()` para APIs distribuidas (Laravel 13 soporta UUIDv7 nativo).

---

## 3. Modelos Eloquent

**Documentación oficial:** `https://laravel.com/docs/13.x/eloquent`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Product extends Model
{
    use SoftDeletes; // si el dominio requiere borrado lógico

    // Mass assignment: lista explícita de campos permitidos
    protected $fillable = [
        'name', 'slug', 'base_price', 'category_id', 'metadata',
    ];

    // Casts para tipos de datos
    protected $casts = [
        'base_price'   => 'decimal:2',
        'metadata'     => 'array',           // JSON → array automático
        'published_at' => 'datetime',
        'is_active'    => 'boolean',
    ];

    // Relaciones declaradas en el modelo
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // local scope para filtrado
    public function scopeByName($query, $name)
    {
        if (!empty($name)) {
            return $query->whereRaw('LOWER(name) like ?', ['%' . strtolower($name) . '%']);
        }
    }
}
```

### Buenas prácticas Eloquent

- Preferí `$fillable` sobre `$guarded = []` — más explícito y seguro.
- Declarar **todos los casts** necesarios para evitar conversiones manuales.
- Usá `->withTrashed()` en Route Model Binding cuando el negocio lo requiere.
- Evitá N+1: usá eager loading (`with()`) siempre que cargues relaciones.
- **Naming de tabla**: Laravel usa convención plural (ej: `Personnel` → `personnels`). Si usás otro nombre, declaralo explícitamente:
  ```php
  protected $table = 'personnel'; // si la tabla no sigue convención plural
  ```

### Comando recomendado

```bash
# Crea modelo + migración + controller + requests (convención correcta de nombres)
php artisan make:model Product -mc --requests
```

---

## 4. Service Layer (Lógica de negocio)

**Archivo:** `app/Services/PersonnelService.php`

Capa que encapsula la lógica de negocio, separada del controlador.

```php

<?php

namespace App\Services;

use App\Models\Personnel;
use Illuminate\Pagination\LengthAwarePaginator;

class PersonnelService
{
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        if (!$filters['pageSize']) $filters['pageSize'] = '15';
        if (!$filters['sort']) $filters['sort'] = 'desc';
        if (!$filters['order']) $filters['order'] = 'id';

        $query = Personnel::withTrashed()
            ->orderBy($filters['order'], $filters['sort'])
            ->byName($filters['name'] ?? null) // scope local para filtrar por nombre
            ->paginate($filters['pageSize']);

        return $query;
    }

    public function getById(int $id): ?Personnel
    {
        return Personnel::find($id);
    }

    public function create(array $data): Personnel
    {
        return Personnel::create($data);
    }

    public function update(Personnel $personnel, array $data): Personnel
    {
        $personnel->update($data);
        return $personnel->fresh();
    }

    public function delete(Personnel $personnel): void
    {
        $personnel->delete();
    }
}
```

---

## 5. Controladores API (Thin Controller)

**Documentación oficial:** `https://laravel.com/docs/13.x/controllers`

El controlador **solo coordina el flujo**: recibe la request, la valida, ejecuta el Service, y retorna la respuesta transformada por un Resource.

### Reglas de controlador

- No debe contener lógica de negocio (delegar siempre a Service).
- Usar FormRequest para validación automática.
- Retornar respuestas usando JsonResource + ApiResponse.
- Si el `ApiResponse` ya resuelve `JsonResource`/`ResourceCollection`, pasar `Resource::make($model)` o `Resource::collection($paginator)` directamente al helper.
- No transformar manualmente los items del paginador dentro del controller si ya existe `ResourceCollection`.
- Usar Route Model Binding para cargar modelos automáticamente.

### Estructura

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonnelRequest;
use App\Http\Requests\UpdatePersonnelRequest;
use App\Http\Requests\IndexPersonnelRequest;
use App\Http\Resources\PersonnelResource;
use App\Services\PersonnelService;
use Illuminate\Http\JsonResponse;
use App\Models\Personnel;
use App\Helpers\ApiResponse;

class PersonnelController extends Controller
{
    public function __construct(
        private readonly PersonnelService $service,
        private readonly ApiResponse $apiResponse
    ) {}

    public function index(IndexPersonnelRequest $request): JsonResponse
    {
        $filters = $request->all();
        $data = $this->service->getAll($filters);
        return $this->apiResponse->success('Personnel retrieved successfully.', PersonnelResource::collection($data));
    }

    public function show(Personnel $personnel): JsonResponse
    {
        return $this->apiResponse->success('Personnel retrieved successfully.', PersonnelResource::make($personnel));
    }

    public function store(StorePersonnelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $personnel = $this->service->create($data);
        return $this->apiResponse->created('Personnel created successfully.', PersonnelResource::make($personnel));
    }

    public function update(UpdatePersonnelRequest $request, Personnel $personnel): JsonResponse
    {
        $data = $request->validated();
        $personnel = $this->service->update($personnel, $data);
        return $this->apiResponse->success('Personnel updated successfully.', PersonnelResource::make($personnel));
    }

    public function destroy(Personnel $personnel): JsonResponse
    {
        $this->service->delete($personnel);
        return $this->apiResponse->noContent();
    }
}
```

### Inyección de dependencias

- El Service se inyecta por **constructor** (`readonly` + typed property).
- El Request se valida automáticamente antes de llegar al método (Route Model Binding + FormRequest).
- El Resource transforma el modelo/colección a JSON.

---

## 6. API Resources / Resource Collections

**Documentación oficial:** `https://laravel.com/docs/13.x/eloquent-resources`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonnelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dni' => $this->dni,
            'email' => $this->email,
            'dependence' => DependenceResource::make($this->whenLoaded('dependence')),
        ];
    }
}
```

### Paginación automática

```php
// Si ApiResponse ya resuelve ResourceCollection, esta es la forma correcta:
$products = Product::query()->paginate(15);

return $apiResponse->success(
    'Products retrieved successfully.',
    ProductResource::collection($products)
);
```

El helper mantiene la metadata de paginación en `meta.pagination` y resuelve cada item usando el resource.

```php
// Evitar este patrón si ApiResponse ya soporta ResourceCollection paginado:
$products->setCollection(
    $products->getCollection()->map(fn (Product $product) => ProductResource::make($product)->resolve())
);
```

### Resource con metadata adicional

```php
public function with(Request $request): array
{
    return ['meta' => ['version' => 'v1']];
}
```

---

## 7. Form Requests / Validación

**Documentación oficial:** `https://laravel.com/docs/13.x/validation#form-request-validation`

```bash
php artisan make:request StorePersonnelRequest
```

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:255'],
            'last_name'    => ['required', 'string', 'max:255'],
            'dni'          => ['required', 'string', 'unique:personnel,dni'],
            'email'        => ['required', 'email', 'unique:personnel,email'],
            'dependence_id' => ['required', 'exists:dependences,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'dependence_id.exists' => 'La dependencia indicada no existe.',
        ];
    }
}
```

### En el controlador

```php
// validated() garantiza que solo lleguen campos validados — nunca $request->all()
$data = $request->validated();

// safe() permite excluir/incluir campos específicos
$data = $request->safe()->except(['admin_override']);
```

---

## 8. Global Exception Handling

**Archivo:** `bootstrap/app.php`

Manejo centralizado de excepciones que retorna el formato `ApiResponse` para todas las rutas `api/*`.

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Helpers\ApiResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Renderizar JSON solo para rutas api/*
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*');
        });

        // ValidationException -> 422
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $apiResponse = new ApiResponse();
                return $apiResponse->unprocessable($e->getMessage(), $e->errors());
            }
        });

        // ModelNotFoundException -> 404
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                $apiResponse = new ApiResponse();
                return $apiResponse->notFound('Resource not found');
            }
        });

        // AuthenticationException -> 401
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                $apiResponse = new ApiResponse();
                return $apiResponse->unauthorized('Unauthenticated');
            }
        });

        // AccessDeniedHttpException -> 403
        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*')) {
                $apiResponse = new ApiResponse();
                return $apiResponse->error('Forbidden', 403);
            }
        });

        // Throwable -> 500
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                $apiResponse = new ApiResponse();
                return $apiResponse->error('Internal server error', 500);
            }
        });
    })->create();
```

### Formato de respuesta de error

```json
{
  "status": 422,
  "message": "Error de validación",
  "data": null,
  "errors": {
    "email": ["El email ya está registrado."]
  },
  "meta": {}
}
```

---

## 9. Autenticación Keycloak

**Paquete:** `robsontenorio/laravel-keycloak-guard`

### Instalación

```bash
composer require robsontenorio/laravel-keycloak-guard
php artisan vendor:publish --provider="Robson\Keycloak\KeycloakServiceProvider" --tag=config
```

### Configuración

**`config/keycloak.php`**
```php
<?php

return [
    'realm_public_key' => env('KEYCLOAK_REALM_PUBLIC_KEY', null),
    'load_user_from_database' => env('KEYCLOAK_LOAD_USER_FROM_DATABASE', true),
    'user_provider_credential' => env('KEYCLOAK_USER_PROVIDER_CREDENTIAL', 'username'),
    'token_principal_attribute' => env('KEYCLOAK_TOKEN_PRINCIPAL_ATTRIBUTE', 'preferred_username'),
    'client_id' => env('KEYCLOAK_CLIENT_ID', null),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET', null),
    'url_auth' => env('KEYCLOAK_URL_AUTH', null),
    'url_users' => env('KEYCLOAK_URL_USERS', null),
];
```

**`config/auth.php`** (agregar guard)
```php
'guards' => [
    'api' => [
        'driver' => 'keycloak',
        'provider' => 'users',
    ],
],
```

### Uso en rutas

```php
// routes/api.php
Route::middleware(['auth:api'])->prefix('v1')->group(function () {
    Route::apiResource('personnel', PersonnelController::class);
});
```

---

## 10. Rutas API

```php
<?php

use App\Http\Controllers\PersonnelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api'])->prefix('v1')->group(function () {
    Route::get('/personnel', [PersonnelController::class, 'index']);
    Route::get('/personnel/{personnel}', [PersonnelController::class, 'show']);
    Route::post('/personnel', [PersonnelController::class, 'store']);
    Route::put('/personnel/{personnel}', [PersonnelController::class, 'update']);
    Route::delete('/personnel/{personnel}', [PersonnelController::class, 'destroy']);
});
```

---

## 11. Procesamiento masivo / Batch Queries

**Documentación oficial:** `https://laravel.com/docs/13.x/queries#chunking-results`

```php
// chunk(n) — leer sin actualizar mientras iterás
Product::chunk(500, function (Collection $products) {
    foreach ($products as $product) {
        // procesamiento...
    }
});

// chunkById(n) — si modificás registros durante la iteración (evita saltar filas)
Product::where('is_active', false)->chunkById(500, function ($products) {
    $products->each->update(['is_active' => true]);
});

// Batch insert eficiente (una sola query)
Product::insert([
    ['name' => 'A', 'slug' => 'a', 'base_price' => 10],
    ['name' => 'B', 'slug' => 'b', 'base_price' => 20],
]);

// Upsert atómico — insert o update si existe (uniqueBy)
Product::upsert(
    [['slug' => 'promo-2025', 'name' => 'Promo', 'base_price' => 5]],
    uniqueBy: ['slug'],
    update:   ['name', 'base_price']
);
```

---

## 12. Convenciones REST

### Versionado desde el inicio

```php
// routes/api.php
Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'));
Route::prefix('v2')->name('api.v2.')->group(base_path('routes/api/v2.php'));
```

### HTTP Status codes correctos

| Situación                  | Code |
|---------------------------|------|
| Lectura exitosa            | 200  |
| Recurso creado             | 201  |
| Eliminado / sin contenido  | 204  |
| Request inválido           | 400  |
| No autenticado             | 401  |
| No autorizado              | 403  |
| No encontrado              | 404  |
| Conflicto de estado        | 409  |
| Error de validación        | 422  |
| Error interno del servidor | 500  |

### Filtrado, paginación y ordenamiento

```
GET /api/v1/products?filter[category]=electronics&sort=-created_at&per_page=20&page=2
```

### Rate Limiting

```php
// bootstrap/app.php o RouteServiceProvider
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// Por segundo (Laravel 11+)
RateLimiter::for('strict', function (Request $request) {
    return Limit::perSecond(5)->by($request->user()?->id);
});
```

### CORS en producción

```php
// config/cors.php — NO usar '*' en producción
'allowed_origins' => ['https://myapp.com', 'https://admin.myapp.com'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
```

---

## Referencia rápida de Artisan

```bash
php artisan make:model Product -mfsc          # model + migration + factory + seeder + controller
php artisan make:controller ProductController --api    # controller API sin rutas HTML
php artisan make:resource ProductResource     # JsonResource
php artisan make:request StoreProduct  # FormRequest
php artisan make:exception BusinessRuleException
php artisan make:service ProductService       # Service layer
php artisan route:list --path=api             # ver todas las rutas API
```

---

## Checklist antes de hacer merge/deploy

- [ ] Migración tiene `up()` y `down()` correctos
- [ ] Modelo tiene `$fillable`, `$casts` y relaciones declaradas
- [ ] Lógica de negocio en Service (no en Controller)
- [ ] Controller usa FormRequest, no `$request->all()`
- [ ] Respuestas usan JsonResource + ApiResponse
- [ ] Todos los endpoints tienen status code correcto
- [ ] Errores retornan formato consistente (`status`, `message`, `data`, `errors`, `meta`)
- [ ] Endpoints protegidos con `auth:api` (Keycloak)
- [ ] Rate limiting configurado para endpoints críticos
- [ ] CORS configurado con origins explícitos
- [ ] API versionada bajo `/v1/`
- [ ] N+1 queries evitadas con `with()` / `whenLoaded()`
- [ ] Excepciones manejadas globalmente en `bootstrap/app.php`

---

## Referencias adicionales

- `references/migrations.md` — columnas, modifiers, índices y tipos completos
- `references/eloquent.md` — relaciones, scopes, observers, events
- `references/auth-keycloak.md` — configuración Keycloak, tokens, roles
- Docs oficiales: https://laravel.com/docs/13.x
