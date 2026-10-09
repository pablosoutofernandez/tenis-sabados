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

        // Montar jornadas, corregir emparejamientos y tocar los pesos del
        // algoritmo: el organizador necesita ambas cosas para hacer su trabajo.
        Gate::define('gestionar-jornadas', fn (User $user) => $user->esOrganizador());
        Gate::define('gestionar-ajustes', fn (User $user) => $user->esOrganizador());

        // Alta/baja de jugadores del torneo, usuarios y auditoría: solo
        // admin (lo cubre el Gate::before de arriba).
        Gate::define('gestionar-jugadores', fn (User $user) => false);
        Gate::define('gestionar-usuarios', fn (User $user) => false);
        Gate::define('ver-auditoria', fn (User $user) => false);
        Gate::define('ver-calculo', fn (User $user) => false); // detalle técnico del emparejador

        // Registrar el resultado de un partido: solo admin y organizador.
        // Los jugadores ya no anotan sus propios sets — demasiado fácil de
        // manipular un resultado que a uno mismo le interesa.
        Gate::define('registrar-resultado', fn (User $user, Partido $partido) => $user->esOrganizador());
        Gate::define('eliminar-jornada',    fn (User $user) => false);
        Gate::define('editar-resultado',    fn (User $user) => false);
    }
}
