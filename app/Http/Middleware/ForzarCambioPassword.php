<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la cuenta arrastra la contraseña inicial (la del seeder, o una que haya
 * puesto el admin al resetear), no se puede usar el resto de la app hasta
 * cambiarla.
 */
class ForzarCambioPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()
            && Auth::user()->debe_cambiar_password
            && ! $request->routeIs('password.cambiar', 'logout')) {
            return redirect()->route('password.cambiar');
        }

        return $next($request);
    }
}
