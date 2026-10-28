<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait GeneratesSlug
{
    protected static function bootGeneratesSlug(): void
    {
        static::creating(function (Model $model): void {
            $model->slug = static::slugFromName($model->name);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('name') || $model->isDirty('slug')) {
                $model->slug = static::slugFromName($model->name);
            }
        });
    }

    public static function slugFromName(string $name): string
    {
        return Str::slug($name);
    }

    public static function slugExists(string $name, ?int $ignoreId = null): bool
    {
        $query = static::withTrashed()->where('slug', static::slugFromName($name));

        if ($ignoreId !== null) {
            $query->where((new static)->getKeyName(), '!=', $ignoreId);
        }

        return $query->exists();
    }
}
