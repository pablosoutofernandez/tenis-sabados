<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta recién registrada existe pero no entra hasta que el admin la
 * aprueba. Si la desactivan estando dentro, se la cierra en la siguiente
 * petición.
 */
class CuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->activo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['name' => 'Tu cuenta todavía no está activada. Habla con el administrador.']);
        }

        return $next($request);
    }
}
