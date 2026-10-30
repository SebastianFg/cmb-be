# Autenticación y autorización

Cómo decide la API quién entra a cada ruta. Son tres capas y ninguna usa
permisos: el acceso se resuelve por **actor** y por **rol** (RNF-02).

```
Request con Authorization: Bearer <token>
│
└─► auth:sanctum
│   Resuelve el token personal y carga el actor: User o Patient
│
└─► ability:patient   (sólo en /portal/*)
│   El token tiene que haber sido emitido para un paciente
│
└─► role:admin,reception[,professional]   (back-office)
│   App\Http\Middleware\EnsureUserHasRole
│   → el actor tiene que ser User y tener alguno de esos roles
│   → si no, 403 con el sobre de ResponseJson
│
└─► Controller
```

## Dos actores, dos tablas

El personal del centro vive en `users` y los pacientes en `patients`. Son
modelos distintos, con login distinto, y ambos emiten tokens de Sanctum:

| Actor | Login | Habilidad del token | Roles |
| --- | --- | --- | --- |
| `User` | `POST /api/v1/auth/users/login` | `user` | `admin`, `reception`, `professional` |
| `Patient` | `POST /api/v1/auth/patients/login` | `patient` | ninguno |

El paciente no tiene roles: no los necesita, porque las rutas que puede tocar
son las de `/portal/*` y se cierran con la habilidad del token.

## El token

`AuthController::login()` borra el token anterior del mismo `device_name` antes
de emitir uno nuevo, así queda **un token vigente por actor y dispositivo**. La
expiración se escribe en el propio token además de en `config/sanctum.php`
(`expiration = 1440`, un día), para que `sanctum:prune-expired` tenga qué
purgar.

`POST /auth/logout` borra sólo el token en uso: las sesiones abiertas en otros
equipos siguen vivas.

Los dos logins están limitados a cinco intentos por minuto, segmentados por
tipo de actor, correo e IP (`AppServiceProvider::registerLoginRateLimiters()`):
probar contraseñas contra una cuenta no bloquea a las demás.

## Los roles

Están en la tabla `roles`, los crea `RoleSeeder` y se asignan por la tabla
intermedia `role_user`:

| Rol | Alcance |
| --- | --- |
| `admin` | Todo el back-office |
| `reception` | Mostrador: fichas, turnos y agenda |
| `professional` | Agenda: sólo la propia |

`EnsureUserHasRole` se registra como alias `role` en `bootstrap/app.php` y se
usa en `routes/api.php`:

```php
Route::middleware(['auth:sanctum', 'role:admin,reception'])->group(...);
```

Rechaza con 403 tanto al paciente (no es un `User`) como al usuario sin el rol
pedido.

### El profesional ve sólo lo suyo

El rol no alcanza para eso, porque dos profesionales tienen el mismo rol. El
recorte es por dato y lo hacen los controladores: `AgendaController` fuerza la
columna del profesional de la sesión y `AppointmentController` rechaza con 403
los turnos de la agenda de otro (`assertBelongsToActor`).

## Lo que no hay

- **No hay permisos.** Los modelos `Role` y `Permission` traen la tabla
  intermedia del template, pero nada del código consulta permisos: no hay
  `can:` en las rutas ni `Gate::before()`.
- **No hay Keycloak ni JWT.** La sesión es un token personal de Sanctum
  guardado en `personal_access_tokens`.
- **No hay servicio central de usuarios.** Las cuentas son locales y las
  siembra `DemoAccountSeeder`.

## Dónde mirar

```
app/
├── Http/
│   ├── Controllers/AuthController.php   # login de los dos actores, logout, me
│   └── Middleware/EnsureUserHasRole.php # alias 'role'
├── Models/
│   ├── User.php                         # personal, HasApiTokens + roles()
│   ├── Patient.php                      # paciente, HasApiTokens
│   └── Role.php
└── Services/ResponseJson.php            # el sobre de toda respuesta
bootstrap/app.php                        # alias role / ability / abilities
config/sanctum.php                       # expiración del token
routes/api.php                           # qué middleware protege cada grupo
```
