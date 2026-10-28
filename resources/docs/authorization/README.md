# Sistema de Autorización por Permisos

## Arquitectura

```
Request con Bearer Token (JWT Keycloak)
│
└─► auth:api (Keycloak Guard)
│   Valida el JWT, extrae el usuario autenticado
│
└─► security (Middleware)
│   Toma UUID del JWT → UserService::getAuthorization()
│   → Cache::remember('authz:{client}:{uuid}', 900)
│   → Si cache miss → HTTP al servicio central /authorizations/{uuid}
│   → Carga roles[] y permisos[] en AuthContext (singleton del request)
│
└─► can:{permiso} (Middleware nativo de Laravel)
│   → Gate::before() registrado en AppServiceProvider
│   → AuthContext::can('incidents.v') → true | false
│   → false → AuthorizationException → Handler.php → 403 JSON
│
└─► Controller (si pasa la autorización)
    → Service (usa getRoles() para visibilidad de datos)
    → Response
```

## Componentes

| Capa | Archivo | Responsabilidad |
|------|---------|-----------------|
| Middleware | `app/Http/Middleware/Security.php` | Ejecuta antes que los controladores. Extrae el UUID del JWT, consulta roles/permisos y los carga en `AuthContext`. |
| Contexto | `app/Security/AuthContext.php` | Singleton por request. Almacena roles y permisos del usuario autenticado. Expone `can()`, `hasRole()`, `roles()`, `permissions()`. |
| Gate dinámico | `app/Providers/AppServiceProvider.php` | `Gate::before()` que resuelve cualquier permiso consultando `AuthContext::can()`. Permite usar `can:` en rutas y `$this->authorize()` en controladores. |
| Cache | `app/Services/UserService.php` | `getAuthorization()` usa `Cache::remember()` con clave `authz:{client}:{uuid}` y TTL 900s (15 min). Usa file cache por defecto; migrable a Redis. |
| Exception Handler | `app/Exceptions/Handler.php` | Captura `AuthorizationException` y responde con JSON 403 consistente con el resto de la API. |
| Permisos | `app/Models/Permission.php` | Constantes con todos los permisos conocidos del sistema. Único lugar donde se definen los nombres. |

## Flujo de datos

### 1. Definir un permiso

Todos los permisos se definen como constantes en `app/Models/Permission.php`:

```php
class Permission extends Model
{
    const INCIDENTS_VIEW = 'incidents.view';
    const INCIDENTS_CREATE = 'incidents.create';
    const CALLS_VIEW = 'calls.view';
    const CALLS_CREATE = 'calls.create';
    // ...
}
```

### 2. Proteger una ruta

En `routes/api.php`, usando la constante:

```php
use App\Models\Permission;

Route::post('/incidents', [IncidentController::class, 'store'])
    ->middleware('can:' . Permission::INCIDENTS_CREATE);
```

### 3. Proteger desde un controlador

```php
public function store(StoreIncidentRequest $request)
{
    $this->authorize(Permission::INCIDENTS_CREATE);
    // ...
}
```

### 4. Verificar en policies (a futuro)

```php
class IncidentPolicy
{
    public function create(User $user): bool
    {
        return app(\App\Security\AuthContext::class)->can(Permission::INCIDENTS_CREATE);
    }
}
```

## Cache

### Configuración actual

- **Driver**: file (por defecto en Laravel)
- **Clave**: `authz:{clientName}:{userUuid}`
- **TTL**: 900 segundos (15 minutos)

### Comportamiento en falla

Si el servicio central de usuarios no responde:
- El cache no se escribe (el valor `null` no se cachea)
- El próximo request reintenta la llamada HTTP
- Mientras tanto, `AuthContext` queda con arrays vacíos → todas las autorizaciones fallan → 403
- Cuando el servicio se recupera, el cache se puebla automáticamente

## Agregar un permiso nuevo

### Backend

1. Agregar la constante en `app/Models/Permission.php`:

```php
const REPORTS_EXPORT = 'reports.export';
```

2. Proteger el endpoint en `routes/api.php`:

```php
Route::get('/reports/export', [ReportController::class, 'export'])
    ->middleware('can:' . Permission::REPORTS_EXPORT);
```

3. El permiso debe existir en el servicio central de usuarios, asignado al rol correspondiente.

### Frontend

1. Agregar el permiso al enum `shared/constants/permission.ts`:

```typescript
export enum PermissionsEnum {
  REPORTS_EXPORT = 'reports.export',
}
```

2. Usarlo con `<Can>`, `useCan()` o `<RequirePermission>` según corresponda.

## Convenciones de nomenclatura

Los permisos usan el formato `{recurso}.{accion}`:

| Sufijo | Significado |
|--------|-------------|
| `.view` | View (ver/listar) |
| `.create` | Create (crear) |
| `.update` | Update (actualizar) |
| `.disable` | Delete (eliminar) |
| `.enable` | Restore (recuperar) |
| `.view-detail` | Info (detalle) |

Ejemplos: `incidents.view`, `calls.create`, `vehicles.update`, `typif.disable`.

## Mantenimiento

### Agregar permisos al sistema

1. Definir la constante en `Permission.php`
2. Agregar el permiso en el servicio central de usuarios
3. Asignar el permiso a los roles correspondientes
4. Proteger los endpoints en las rutas
5. (Opcional) Agregar el permiso al frontend

### Deprecar un permiso

1. Mantener la constante en `Permission.php` (no romper rutas existentes)
2. Eliminar el middleware `can:` de las rutas
3. Eliminar la asignación del servicio central
4. En una limpieza posterior, eliminar la constante

### Solución de problemas

**Síntoma**: Endpoint devuelve 403 aunque el usuario debería tener acceso.
- Verificar que el permiso existe en el servicio central de usuarios
- Verificar que el usuario tiene el permiso asignado
- Revisar el cache: `php artisan cache:clear` o esperar 15 min
- Verificar que el string del permiso coincide exactamente (case-sensitive)

**Síntoma**: Endpoint devuelve 401.
- El token JWT no es válido o expiró
- El UUID (`sub` claim) no está presente en el token

**Síntoma**: Lentitud en respuestas.
- El cache file puede ser más lento en entornos con mucho I/O
- Migrar a Redis elimina la latencia

## Estructura de archivos

```
cio911-be/
├── app/
│   ├── Exceptions/
│   │   └── Handler.php              # Captura AuthorizationException → 403
│   ├── Http/
│   │   ├── Kernel.php               # Registro del middleware 'security'
│   │   └── Middleware/
│   │       └── Security.php          # Middleware que carga el contexto
│   ├── Models/
│   │   └── Permission.php            # Constantes de permisos
│   ├── Providers/
│   │   └── AppServiceProvider.php     # Singleton AuthContext + Gate::before()
│   ├── Security/
│   │   └── AuthContext.php            # Contexto de autorización por request
│   └── Services/
│       └── UserService.php            # Servicio con cache a API central
└── routes/
    └── api.php                        # Rutas protegidas con can:
```
