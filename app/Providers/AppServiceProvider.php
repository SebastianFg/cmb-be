<?php

namespace App\Providers;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'users' => User::class,
            'patients' => Patient::class,
        ]);

        $this->registerLoginRateLimiters();
    }

    /**
     * Cinco intentos por minuto, segmentados por tipo de actor, email e IP:
     * probar contraseñas contra una cuenta no bloquea al resto.
     */
    private function registerLoginRateLimiters(): void
    {
        foreach (['users', 'patients'] as $actorType) {
            RateLimiter::for('login-'.$actorType, function (Request $request) use ($actorType): Limit {
                $email = Str::lower((string) $request->input('email'));

                return Limit::perMinute(5)->by($actorType.'|'.$email.'|'.$request->ip());
            });
        }
    }
}
