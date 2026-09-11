<?php

namespace Database\Seeders;

use App\Models\Jugador;
use Illuminate\Database\Seeder;

class JugadoresSeeder extends Seeder
{
    /**
     * Plantilla del torneo. Ajusta el nivel de cada uno desde la pantalla
     * de Jugadores según lo que sepas de su juego.
     */
    public function run(): void
    {
        $jugadores = [
            'Adrián', 'Alex', 'Amado', 'Ángel', 'Aníbal', 'Berto', 'Cabo',
            'Eladio', 'Helder', 'Jose', 'Marta', 'Moncho', 'Ocampo',
            'Pablo', 'Quino', 'Ricardo', 'Teijeiro',
        ];

        foreach ($jugadores as $nombre) {
            Jugador::firstOrCreate(
                ['nombre' => $nombre],
                ['nivel' => 5.0, 'activo' => true],
            );
        }
    }
}
