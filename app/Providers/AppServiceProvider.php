<?php

namespace App\Providers;

use App\Models\Partido;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
                      URL::forceScheme('https');
                    }
        \Illuminate\Support\Carbon::setLocale('es');

        // El admin puede todo: se resuelve antes que cualquier gate concreto.
        Gate::before(fn (User $user) => $user->esAdmin() ? true : null);

        // Ver clasificación, puntos y niveles. El organizador reparte a ciegas.
        Gate::define('ver-puntuaciones', fn (User $user) => $user->puedeVerPuntuaciones());

        // Montar jornadas y corregir emparejamientos.
        Gate::define('gestionar-jornadas', fn (User $user) => $user->esOrganizador());

        // Alta/baja de jugadores del torneo, ajustes del algoritmo, usuarios
        // y auditoría: solo admin (lo cubre el Gate::before de arriba).
        Gate::define('gestionar-jugadores', fn (User $user) => false);
        Gate::define('gestionar-ajustes', fn (User $user) => false);
        Gate::define('gestionar-usuarios', fn (User $user) => false);
        Gate::define('ver-auditoria', fn (User $user) => false);

        // Registrar el resultado de un partido: organizador siempre; el
        // jugador vinculado solo en los partidos que juega él.
        Gate::define('registrar-resultado', function (User $user, Partido $partido) {
            if ($user->esOrganizador()) {
                return true;
            }

            return $user->juegaElPartido($partido);
        });
    }
}
