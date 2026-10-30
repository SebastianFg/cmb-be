# cmb-be

Backend del Sistema de Gestión de Turnos Médicos del Centro Médico Belgrano
(UGD · Aplicaciones Web y Móvil). Laravel 13 + PostgreSQL 18, API REST
versionada bajo `/api/v1` y sesión por token personal de Laravel Sanctum.

El cliente que la consume es `cmb-fe`. El análisis del TP está en
`doc/tp1-documento.html` y el prototipo de pantallas en `doc/vistas/`.

## Requisitos

Todo corre dentro de Docker, así que en la máquina sólo hacen falta:

- Docker y Docker Compose
- WSL 2 (sólo en Windows)

**No hace falta PHP ni Composer instalados**: el proyecto pide PHP 8.5.12 y la
imagen `php-85` que arma `docker/8.5/Dockerfile` ya lo trae. Todo comando de
`artisan`, `composer`, `pint` o `pest` se ejecuta dentro del contenedor.

## Puesta en marcha

### 1. Variables de entorno

```bash
cp .env.example .env
```

Claves que hay que completar antes de levantar nada:

| Variable | Para qué | Valor sugerido |
| --- | --- | --- |
| `APP_PORT` | Puerto donde queda la API en la máquina | `8000` |
| `DB_PORT_DOCKER` | Puerto donde queda PostgreSQL en la máquina | `5433` |
| `DB_DATABASE` | Nombre de la base | `cmb_db` |
| `DB_USERNAME` / `DB_PASSWORD` | Credenciales de PostgreSQL | libres |
| `WWWUSER` / `WWWGROUP` | UID y GID del usuario dentro del contenedor | `1000` (lo que devuelve `id -u` / `id -g`) |

> **Los dos puertos tienen que ser distintos.** `APP_PORT` publica el contenedor
> de la API y `DB_PORT_DOCKER` el de la base: si coinciden, `docker compose up`
> falla porque el puerto ya está tomado.

`DB_HOST` queda en `cmb-db`, que es el nombre del servicio dentro de la red de
Compose, y `DB_PORT` en `5432`, que es el puerto **interno** de PostgreSQL.
`DB_PORT_DOCKER` sólo importa para conectarse desde afuera (por ejemplo con
DBeaver o `psql`).

### 2. Levantar los contenedores

```bash
docker compose up -d
```

La primera vez construye la imagen de PHP, así que tarda unos minutos. Cuando
termina quedan corriendo los tres contenedores y la API ya está escuchando,
aunque todavía no responda: le faltan las dependencias y la base.

### 3. Instalar dependencias y preparar la aplicación

```bash
docker compose exec cmb-api composer install
docker compose exec cmb-api php artisan key:generate
chmod -R 777 storage/ bootstrap/cache/
```

Los permisos van sobre la carpeta del proyecto porque está montada dentro del
contenedor: sin eso Laravel no puede escribir logs ni caché.

### 4. Migrar y sembrar

```bash
docker compose exec cmb-api php artisan migrate --seed
```

El seed deja el centro listo para usar: roles, especialidades, profesionales,
consultorios, obras sociales, feriados, agendas semanales, pacientes y unos
veinte turnos repartidos entre los días siguientes.

### 5. Comprobar que responde

```bash
curl http://localhost:8000/api/v1/health
```

Responde `{"status":"ok","timestamp":"…"}`. Con eso el backend ya está listo
para que `cmb-fe` apunte a `http://localhost:8000/api/v1`.

## Cómo se levanta la API

**No hay que ejecutar `php artisan serve` a mano.** El contenedor `cmb-api` lo
hace solo: su entrypoint arranca `supervisord`, que mantiene vivo

```
php -d variables_order=EGPCS artisan serve --host=0.0.0.0 --port=80
```

y Compose publica ese puerto 80 interno en el `APP_PORT` de la máquina. Por eso
alcanza con `docker compose up -d` para tener la API en
`http://localhost:8000/api/v1`, y por eso si el proceso se cae supervisor lo
vuelve a levantar.

| Qué querés hacer | Comando |
| --- | --- |
| Arrancar todo | `docker compose up -d` |
| Ver el estado | `docker compose ps` |
| Ver el log de la API en vivo | `docker compose logs -f cmb-api` |
| Reiniciar sólo la API | `docker compose restart cmb-api` |
| Parar todo | `docker compose stop` |
| Parar y borrar los contenedores | `docker compose down` |
| Borrar también la base | `docker compose down -v` |

Supervisor manda la salida de `artisan serve` a la salida estándar, así que
`docker compose logs -f cmb-api` muestra cada request con su tiempo de
respuesta. Los errores de la aplicación siguen yendo a
`storage/logs/laravel.log`.

Como el proyecto está montado dentro del contenedor, **los cambios en el código
se ven en el request siguiente**: no hay que reiniciar nada. Sí hay que hacerlo
cuando se toca el `.env` o algo de `config/`:

```bash
docker compose exec cmb-api php artisan optimize:clear
```

`docker compose down -v` borra el volumen `cmb-volume`, o sea la base entera:
para volver a empezar hay que repetir `migrate --seed`. Sin el `-v` los datos
sobreviven.

### Sin Docker

Se puede, pero la máquina necesita PHP 8.5.12 con `pdo_pgsql` y un PostgreSQL
18 a mano. En ese caso hay que apuntar la conexión al puerto publicado en vez
de al nombre del servicio:

```env
DB_HOST=127.0.0.1
DB_PORT=5433        # el valor de DB_PORT_DOCKER
```

y después `composer install`, `php artisan migrate --seed` y
`php artisan serve`. La vía recomendada sigue siendo Docker: la versión de PHP
que pide el proyecto es más nueva que la que suele venir instalada.

## Cuentas sembradas

Una por rol, todas con la misma contraseña, que sale de `SEED_ACCOUNT_PASSWORD`
(por defecto `Belgrano#2026$Seg`):

| Correo | Rol | Entra a |
| --- | --- | --- |
| `admin@example.com` | admin | Back-office |
| `reception@example.com` | reception | Back-office |
| `professional@example.com` | professional | Back-office (sólo su agenda) |
| `patient@example.com` | patient | Portal del paciente |

El personal entra por `POST /api/v1/auth/users/login` y los pacientes por
`POST /api/v1/auth/patients/login`; son dos tablas distintas y dos endpoints
distintos, aunque el formulario del frontend sea uno solo.

## Comandos del día a día

Todo va con el prefijo `docker compose exec cmb-api`:

| Comando | Para qué |
| --- | --- |
| `php artisan test --compact` | Corre la suite de Pest |
| `php artisan test --compact --filter=Agenda` | Corre un archivo o una prueba |
| `vendor/bin/pint` | Formatea el código PHP |
| `vendor/bin/pint --test` | Verifica el formato sin tocar nada |
| `php artisan route:list --path=api` | Lista la API |
| `php artisan migrate:fresh --seed` | Rehace la base desde cero |
| `php artisan optimize:clear` | Limpia caché de configuración y rutas |

Las pruebas usan `RefreshDatabase` sobre la misma base de desarrollo, así que
cada corrida la deja migrada y vacía: después de testear conviene volver a
correr `php artisan migrate:fresh --seed` si se quiere seguir usando el front
con datos.

## Contenedores

| Nombre | Imagen | Qué hace | Puerto en la máquina |
| --- | --- | --- | --- |
| `cmb-api` | `php-85` | Nginx + PHP-FPM con la aplicación | `APP_PORT` |
| `cmb-queue` | `php-85` | `queue:work` para los trabajos en cola | — |
| `cmb-db` | `postgres:18.4` | Base de datos, con volumen `cmb-volume` | `DB_PORT_DOCKER` |

## Qué expone la API

Todo cuelga de `/api/v1` (ver `routes/api.php`):

| Grupo | Rutas | Quién entra |
| --- | --- | --- |
| Salud | `GET /health` | Público |
| Sesión | `POST /auth/users/login`, `POST /auth/patients/login`, `GET /auth/me`, `POST /auth/logout` | Público / con token |
| Catálogos | `GET /nationalities`, `/health-insurances`, `/specialties`, `/professionals` | Con token |
| Disponibilidad | `GET /availability/days`, `GET /availability/slots` | Con token |
| Portal | `GET`/`POST /portal/appointments` | Paciente |
| Mostrador | `GET`/`POST /patients`, `POST /appointments` | admin, reception |
| Agenda | `GET /agenda`, `GET`/`PATCH`/`PUT /appointments/{id}`, `POST /appointments/{id}/cancel`, `POST /schedule-exceptions` | admin, reception, professional |
| Reportes | `GET /reports/dashboard-summary` | admin, reception, professional |

Las respuestas salen siempre con el mismo sobre (`ResponseJson`): `message`,
`statusCode` y `data`. Los errores de validación también viajan en `data`, no
en `errors`.

El token de Sanctum va en `Authorization: Bearer`. Los pacientes reciben la
habilidad `patient` y el personal la habilidad `user`, y además cada ruta del
back-office filtra por rol con el middleware `role`.

Las restricciones de exclusión de PostgreSQL (`btree_gist`) son la última
palabra sobre los solapamientos: un profesional, un consultorio o un paciente
no pueden tener dos turnos pisados aunque dos sesiones los pidan al mismo
tiempo.

## Levantar el sistema completo

Este repositorio es sólo la API. Para ver las pantallas hay que levantar
también `cmb-fe`, que es el que sirve la interfaz en `http://localhost:3000` y
consume esta API. Los pasos están en `../cmb-fe/README.md`; el resumen es:

```bash
# terminal 1 — backend (la primera vez, los cinco pasos de arriba)
cd cmb-be
cp .env.example .env
docker compose up -d
docker compose exec cmb-api composer install
docker compose exec cmb-api php artisan key:generate
docker compose exec cmb-api php artisan migrate --seed

# terminal 2 — frontend
cd cmb-fe
cp .env.example .env
pnpm install
pnpm dev
```

Después del primer arranque alcanza con `docker compose up -d` de un lado y
`pnpm dev` del otro.

Con `VITE_AUTH_MODE=sanctum` en `cmb-fe/.env` el front pega contra esta API;
con `prototype` funciona solo, sin backend.

## Convenciones

Ver `AGENTS.md` y `CLAUDE.md`. El código PHP se formatea con Pint y las pruebas
se escriben con Pest.
