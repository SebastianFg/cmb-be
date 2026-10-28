---
name: laravel-api-common-tests
description: >
  Genera pools de pruebas Pest para las APIs de este proyecto Laravel 13 usando
  el contrato real, FormRequests, Resources, relaciones y persistencia.
  Activar al crear o revisar pruebas de endpoints, validaciones, permisos,
  CRUD, SoftDeletes o regresiones.
license: Apache-2.0
metadata:
  author: gentleman-programming
  version: "1.0"
---

# Pool de pruebas API del proyecto

Aplica la matriz completa de la skill global `laravel-api-common-tests`, pero
adapta cada caso al contrato real de este repositorio. Nunca inventes una regla
que no exista en el FormRequest o en la logica del dominio; reporta esa ausencia
como riesgo.

## Matriz de reglas aplicables

Selecciona solo los casos respaldados por el contrato real:

- `required`: prueba ausencia, `null` cuando no sea `nullable` y string vacio
  para strings cuando corresponda.
- `nullable`: acepta `null`, pero mantiene las demas reglas cuando hay valor;
  no exijas presencia sin `present`, `required` o equivalente.
- `integer`: rechaza strings no numericos y decimales; con `min:1`, prueba `0`
  y negativos; prueba limites `min`/`max` y valores fuera de ellos. No asumas
  que `"1"` es invalido si Laravel lo acepta con las reglas usadas.
- `string`: rechaza arrays y objetos; con `min`/`max`, prueba limite y fuera de
  limite; prueba strings vacios cuando corresponda.
- `boolean`: respeta el formato de la regla; solo prueba el rechazo de `1`, `0`,
  `"true"` y `"false"` si la validacion es estricta.
- `unique`: prueba duplicado, valor nuevo y conservacion del propio valor en
  update; con SoftDeletes, prueba el comportamiento de registros eliminados.
- `exists`: prueba ID existente e inexistente, tipos invalidos y restricciones
  de minimo/nullability; rechaza soft-deleted cuando la regla excluya `deleted_at`.
- `in`/enum: acepta los valores permitidos y rechaza fuera de lista; cubre cada
  alternativa que cambie el comportamiento.
- `array`: rechaza string, integer y objeto cuando corresponda; prueba vacio,
  ausencia en required, presencia con `present` y cada regla `campo.*` de forma
  independiente.
- Arrays de IDs: prueba IDs validos, inexistentes, soft-deleted, duplicados con
  `distinct`, tipos incorrectos y relaciones resultantes.
- Fechas: rechaza formatos invalidos; prueba limites de `before`, `after`,
  `before_or_equal` y `after_or_equal` usando fechas fijas.
- URLs: acepta URLs validas y rechaza formatos invalidos; prueba `max` si existe.
- Slugs: prueba duplicados, updates y valor generado automaticamente; si lo
  envia el cliente, aplica sus reglas reales.

Si falta una validacion importante, reportala como riesgo en vez de inventarla.

## Pools por operacion

- Autenticacion: todo endpoint protegido debe probar acceso sin autenticacion y
  usar el codigo definido por el proyecto, normalmente `401`.
- Autorizacion: prueba usuario autorizado y usuario autenticado sin permiso,
  normalmente `403`; separa siempre `401` de `403`.
- Store: payload valido, status, JSON, persistencia, relaciones y ausencia de
  registros parciales ante payload invalido.
- Update: modificacion valida persistida, inexistente, campos omitidos sin cambio,
  relaciones actualizadas, propio valor `unique` y diferencias PUT/PATCH si
  existen.
- Delete: existente, inexistente, eliminacion real y restricciones por relaciones;
  con SoftDeletes usa `assertSoftDeleted` y comprueba `deleted_at`.
- Show: existente, inexistente, estructura, relaciones incluidas y ausencia de
  campos sensibles excluidos por el Resource.
- Index: coleccion, estructura, paginacion, filtros, ordenamiento y exclusion de
  soft-deleted cuando corresponda.

## Relaciones, base de datos y regresiones

- Comprueba en base de datos toda relacion creada, sincronizada o removida;
  cubre IDs validos/invalidos, soft-deleted, `sync` y relaciones opcionales.
- Usa factories, `RefreshDatabase` y setup local; cada test debe ser
  independiente, sin datos persistidos ni orden de ejecucion.
- Las operaciones fallidas no deben dejar datos parciales.
- Un status correcto no basta: comprueba JSON, campos, relaciones y persistencia.
- En `422`, comprueba campos especificos; prueba `404`, `401` y `403` cuando
  corresponda.
- Cada bug corregido debe tener una prueba de regresion; no elimines ni relajes
  assertions existentes y evita duplicados.

## Convenciones locales

- Usa Pest 4 en `tests/Feature` y `tests/Unit`, siguiendo el estilo del archivo
  vecino.
- Inspecciona `routes/api.php`, `app/Http/Controllers`, `app/Http/Requests`,
  `app/Http/Resources`, `app/Models`, `app/Services` y `app/Policies`.
- Las rutas privadas usan `auth:api`, `security` y normalmente el permiso
  `dashboard`.
- Reutiliza el setup de autenticacion con guard `api`, `actingAs`, token
  simulado, `AuthContext` y `Http::fake()` cuando el endpoint lo requiera.
- El envelope actual utiliza `statusCode`, `message`, `data` y `pagination`;
  comprueba esos nombres solo cuando el endpoint los devuelva.
- Reutiliza factories, seeders, helpers y `RefreshDatabase` existentes.
- Para catalogos read-only, comprueba que los verbos no expuestos respondan
  `405`.
- Para recursos con restauracion, cubre `DELETE`, `deleted_at`, ausencia en
  listados y `restore` segun el contrato.
- No hay coleccion Bruno actualmente. Si aparece una, actualiza solo requests y
  ejemplos afectados por cambios reales.

## Ejecucion y entrega

Ejecuta primero el archivo o filtro afectado:

```bash
php artisan test --compact tests/Feature/ArchivoTest.php
```

Si modificas PHP, aplica Pint:

```bash
vendor/bin/pint --dirty --format agent
```

Entrega el pool seleccionado, tests creados o modificados, comandos y
resultados, cambios Bruno si existen, y riesgos detectados.
