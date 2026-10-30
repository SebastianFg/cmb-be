<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Services\ResponseJson;
use App\Services\StatusCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
        // CORS middleware
        $middleware->use([
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ResponseJson::unauthenticate($exception->getMessage());
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ResponseJson::error(
                $exception->getMessage() ?: 'No autorizado para realizar esta accion.',
                StatusCode::HTTP_FORBIDDEN,
            );
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ResponseJson::not_found($exception->getMessage());
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ResponseJson::unprocessable($exception->getMessage(), $exception->errors());
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof AuthorizationException || $exception instanceof AccessDeniedHttpException) {
                return ResponseJson::error(
                    $exception->getMessage() ?: 'No autorizado para realizar esta accion.',
                    StatusCode::HTTP_FORBIDDEN,
                );
            }

            if ($exception instanceof HttpExceptionInterface) {
                return ResponseJson::error(
                    $exception->getMessage(),
                    $exception->getStatusCode(),
                );
            }

            if ($exception instanceof NotFoundHttpException) {
                return ResponseJson::not_found('El recurso solicitado no existe.');
            }

            if ($exception instanceof MethodNotAllowedHttpException) {
                return ResponseJson::error($exception->getMessage(), StatusCode::HTTP_METHOD_NOT_ALLOWED);
            }

            if ($exception->getPrevious() instanceof ModelNotFoundException) {
                return ResponseJson::not_found('El recurso solicitado no existe o fue deshabilitado.');
            }

            return ResponseJson::error($exception->getMessage(), StatusCode::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
