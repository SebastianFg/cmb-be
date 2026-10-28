# Referencia: Migraciones Laravel 12

## Tipos de columnas más usados en APIs

```php
$table->id();                          // BIGINT UNSIGNED AUTO_INCREMENT PK
$table->uuid('id')->primary();         // UUID como PK (distribuido)
$table->ulid('id')->primary();         // ULID como PK
$table->string('name', 255);           // VARCHAR
$table->text('description');           // TEXT
$table->longText('body');              // LONGTEXT
$table->integer('qty');                // INT
$table->bigInteger('views');           // BIGINT
$table->unsignedBigInteger('user_id'); // alternativa a foreignId
$table->decimal('price', 10, 2);       // DECIMAL(10,2)
$table->float('lat');                  // FLOAT (precisión opcional)
$table->double('amount');              // DOUBLE
$table->boolean('is_active');          // TINYINT(1)
$table->json('metadata');              // JSON
$table->date('birth_date');            // DATE
$table->dateTime('scheduled_at');      // DATETIME
$table->timestamp('verified_at');      // TIMESTAMP nullable
$table->timestamps();                  // created_at + updated_at
$table->softDeletes();                 // deleted_at (nullable TIMESTAMP)
$table->enum('status', ['draft','published','archived']); // ENUM
```

## Foreign Keys

```php
// Forma corta (recomendada)
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->foreignId('category_id')->constrained('categories')->nullOnDelete();

// Con nombre explícito de FK
$table->foreignId('parent_id')
      ->nullable()
      ->constrained('categories')
      ->nullOnDelete();

// Forma explícita (más control)
$table->unsignedBigInteger('role_id');
$table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
```

## Modificadores de columna

```php
->nullable()                 // permite NULL
->default('value')           // valor por defecto
->after('column')            // posición en MySQL
->unsigned()                 // solo positivo (integers)
->comment('texto')           // comentario de columna
->unique()                   // índice único
->index()                    // índice regular
->fullText()                 // índice full-text
```

## Índices

```php
$table->unique(['email', 'tenant_id']);          // unique compuesto
$table->index(['status', 'created_at']);         // índice compuesto
$table->index('slug', 'idx_products_slug');      // índice con nombre
$table->dropIndex('idx_products_slug');          // eliminar índice
$table->dropUnique(['email']);                   // eliminar unique
$table->dropForeign(['category_id']);            // eliminar FK
```

## Schema operations útiles

```php
// Cambiar tipo/atributo de columna
Schema::table('products', function (Blueprint $table) {
    $table->string('name', 500)->change(); // ampliar VARCHAR
});

// Renombrar columna (Laravel 12: sin Doctrine DBAL)
$table->renameColumn('old_name', 'new_name');

// Renombrar tabla
Schema::rename('old_table', 'new_table');

// Verificar existencia antes de actuar
if (Schema::hasTable('products')) { ... }
if (Schema::hasColumn('products', 'sku')) { ... }
```

## Squash de migraciones

Cuando el directorio `database/migrations` se vuelve muy grande:

```bash
php artisan schema:dump          # genera database/schema/{connection}.sql
php artisan schema:dump --prune  # + elimina migraciones existentes
```

Laravel ejecutará primero el schema SQL y luego las migraciones nuevas.

## Tips de producción

1. **No modificar migraciones viejas** — crear nuevas para cada cambio.
2. **`--pretend`** para ver SQL antes de ejecutar:
   ```bash
   php artisan migrate --pretend
   ```
3. **Bloqueo de rollback en producción:**
   ```php
   public function down(): void
   {
       if (app()->isProduction()) {
           throw new \RuntimeException('Rollback deshabilitado en producción.');
       }
       Schema::dropIfExists('products');
   }
   ```
