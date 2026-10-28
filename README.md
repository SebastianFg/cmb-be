<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

# Template Laravel 13

Backend base en Laravel 13 con PostgreSQL y entorno Docker para desarrollo local.

## Requisitos

- Docker
- Docker Compose
- WSL 2 (solo para Windows)

## Configuracion

1. Copiar `.env.example` y renombrarlo a `.env`.
2. Configurar las variables de entorno necesarias.

Variables principales:

```env
APP_PORT=
DB_PORT_DOCKER=

DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```
3. Copiar `docker-compose.dev.example.yml` o `docker-compose.local.example.yml`, según el ambiente a desplegar, y renombrarlo como `docker-compose.yml`.

Notas:

- La aplicacion usa PostgreSQL (`DB_CONNECTION=pgsql`).
- El contenedor de la API se expone en `APP_PORT`.
- El contenedor de base de datos se expone en `DB_PORT_DOCKER`.

## Instalacion

1. Levantar los contenedores:

```bash
docker compose up -d
```

2. Instalar dependencias PHP:

```bash
docker compose exec cmb-api composer install
```

3. Generar la clave de la aplicacion:

```bash
docker compose exec cmb-api php artisan key:generate
```

4. Ejecutar migraciones:

```bash
docker compose exec cmb-api php artisan migrate --seed
```

5. Dar permisos:

```
chmod -R 777 storage/ bootstrap/
```

## Comandos Utiles

Ejecutar comandos dentro del contenedor:

```bash
docker compose exec cmb-api [comando]
```

Ejemplos:

```bash
docker compose exec cmb-api php artisan optimize:clear
docker compose exec cmb-api php artisan test
```

## Testing

Para correr tests:

```bash
php artisan test
```

O usando el script de Composer:

```bash
composer test
```

## Acceso Local

- Aplicacion: `http://localhost:APP_PORT`
- Base de datos PostgreSQL: `localhost:DB_PORT_DOCKER`

## Contenedores

- API: `cmb-api`
- Base de datos: `cmb-db`
