<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ResponseJson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Deja pasar sólo a usuarios del centro con alguno de los roles pedidos.
     * El paciente nunca entra acá: es otro actor y no tiene roles.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return ResponseJson::error(
                'Esta operación es del personal del centro.',
                Response::HTTP_FORBIDDEN,
            );
        }

        if (! $actor->roles()->whereIn('name', $roles)->exists()) {
            return ResponseJson::error(
                'No tenés permiso para realizar esta operación.',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
