<?php

namespace Database\Seeders;

use App\Models\Jugador;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Cuenta de administrador inicial.
     *
     * Se identifica por nombre, no por correo (esta app no usa correo).
     * La contraseña se guarda hasheada (el cast 'hashed' del modelo se
     * encarga) y queda marcada para cambio obligatorio: al entrar por
     * primera vez, la app no deja hacer nada más hasta cambiarla.
     */
    public function run(): void
    {
        $nombre = env('ADMIN_NOMBRE', 'Pablo');

        $admin = User::firstOrNew(['name' => $nombre]);

        if ($admin->exists) {
            $this->command?->info('La cuenta de administrador ya existe: '.$nombre);

            return;
        }

        $admin->fill([
            'password'              => env('ADMIN_PASSWORD', 'contraseña'),
            'rol'                   => User::ROL_ADMIN,
            'activo'                => true,
            'debe_cambiar_password' => true,
            // Si ya existe un jugador con este nombre en el torneo, se vincula solo.
            'jugador_id'            => Jugador::where('nombre', $nombre)->value('id'),
        ])->save();

        $this->command?->info('Administrador creado: '.$nombre.' (contraseña inicial: contraseña)');
        $this->command?->warn('Cámbiala al entrar por primera vez — la app te lo va a exigir.');
    }
}
