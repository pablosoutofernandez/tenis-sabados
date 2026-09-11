<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Primero los jugadores del torneo: así el admin puede vincularse
        // con su ficha si ya existe una llamada "Pablo".
        $this->call(JugadoresSeeder::class);
        $this->call(AdminSeeder::class);
    }
}
