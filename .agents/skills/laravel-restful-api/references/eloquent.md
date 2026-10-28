# Referencia: Eloquent Avanzado

## Relaciones completas

```php
// Uno a uno
public function profile(): HasOne
{
    return $this->hasOne(Profile::class);
}

// Uno a muchos
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}

// Muchos a uno (inverse)
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

// Muchos a muchos
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class)
                ->withPivot('assigned_at')
                ->withTimestamps();
}

// Has One Through
public function carInsurance(): HasOneThrough
{
    return $this->hasOneThrough(Insurance::class, Car::class);
}

// Has Many Through
public function countryUsers(): HasManyThrough
{
    return $this->hasManyThrough(User::class, State::class);
}

// Polimórfica
public function comments(): MorphMany
{
    return $this->morphMany(Comment::class, 'commentable');
}
```

## Local Scopes

```php
// Definición
public function scopeActive(Builder $query): void
{
    $query->where('is_active', true);
}

public function scopeByCategory(Builder $query, int $categoryId): void
{
    $query->where('category_id', $categoryId);
}

// Uso
Product::active()->byCategory(3)->get();
```

## Observers

```php
php artisan make:observer ProductObserver --model=Product
```

```php
class ProductObserver
{
    public function creating(Product $product): void
    {
        $product->slug = Str::slug($product->name);
    }

    public function deleting(Product $product): void
    {
        $product->reviews()->delete(); // limpieza manual si no hay cascade
    }
}

// Registro en AppServiceProvider
Product::observe(ProductObserver::class);
```

## Eager Loading para evitar N+1

```php
// Siempre cargar relaciones necesarias
Product::with(['category', 'tags', 'reviews.user'])->paginate();

// Condicional (solo si fue cargada)
'author' => AuthorResource::make($this->whenLoaded('author'))

// Lazy eager loading (cuando ya tenés la colección)
$products->load('category');

// WithCount
Product::withCount('reviews')->get();
// acceso: $product->reviews_count
```

## Eventos Eloquent disponibles

`creating`, `created`, `updating`, `updated`, `saving`, `saved`,
`deleting`, `deleted`, `restoring`, `restored`, `retrieved`

## Accessors y Mutators (Laravel 9+ syntax)

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn () => "{$this->first_name} {$this->last_name}",
        set: fn (string $value) => [
            'first_name' => explode(' ', $value)[0],
            'last_name'  => explode(' ', $value)[1] ?? '',
        ],
    );
}
```
