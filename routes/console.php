<?php

use App\Services\Emparejamiento\Simulador;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Simula temporadas con el emparejador para calibrar los ajustes. Trabaja
 * siempre sobre una base SQLite en memoria: nunca toca los datos reales.
 *
 *   php artisan tenis:simular                       -> ajustes por defecto
 *   php artisan tenis:simular --lider=5 --equilibrio=4
 *   php artisan tenis:simular --barrido             -> cada ajuste de 1 a 5
 */
Artisan::command('tenis:simular
    {--semanas=30 : Jornadas por temporada}
    {--semillas=4 : Temporadas distintas a promediar}
    {--parejas=3} {--rivales=3} {--equilibrio=3} {--lider=3}
    {--barrido : Mueve cada ajuste de 1 a 5 dejando el resto en el valor dado}', function () {
    config(['database.connections.simulacion' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('simulacion');

    $base = [
        'parejas'    => (int) $this->option('parejas'),
        'rivales'    => (int) $this->option('rivales'),
        'equilibrio' => (int) $this->option('equilibrio'),
        'lider'      => (int) $this->option('lider'),
    ];

    $escenarios = ['actual' => $base];
    if ($this->option('barrido')) {
        $escenarios = [];
        foreach (array_keys($base) as $ajuste) {
            foreach (range(1, 5) as $nivel) {
                $escenarios["{$ajuste}={$nivel}"] = [...$base, $ajuste => $nivel];
            }
        }
    }

    $filas = [];
    foreach ($escenarios as $nombre => $ajustes) {
        $resultados = [];
        foreach (range(1, (int) $this->option('semillas')) as $semilla) {
            Artisan::call('migrate:fresh', ['--database' => 'simulacion', '--force' => true]);
            $resultados[] = app(Simulador::class)->temporada($ajustes, (int) $this->option('semanas'), $semilla);
        }

        $media = collect($resultados[0])->keys()->mapWithKeys(fn ($k) => [
            $k => round(collect($resultados)->avg($k), 2),
        ]);
        $filas[] = [$nombre, ...$media->values()->all()];
        $this->output->write('.');
    }

    $this->newLine();
    $this->table(
        ['escenario', 'dif media', 'dif máx', '% ≥3', 'pareja sem. pasada', 'pareja últ. 4', 'rival sem. pasada', 'semanas c/ líder', '% líder c/ flojo', 'rango compi líder (0=flojo)', 'plus líder medio'],
        $filas,
    );
})->purpose('Simula temporadas con el emparejador para calibrar los ajustes (BD en memoria)');

/*
 * Repite la temporada con el Elo actual (config/tenis.php -> elo) y enseña
 * cómo quedaría cada nivel. Sin --guardar no cambia nada.
 */
Artisan::command('tenis:recalcular-niveles {--anio= : Temporada (por defecto, la actual)} {--factor= : Multiplica k (por defecto tenis.elo.factor_recalculo)} {--guardar : Aplica el resultado}', function () {
    $anio      = (int) ($this->option('anio') ?: now()->year);
    $servicio  = app(\App\Services\RecalculoNiveles::class);
    $factor    = (float) ($this->option('factor') ?? config('tenis.elo.factor_recalculo', 0.6));
    $recalculo = $servicio->calcular($anio, $factor);

    if ($recalculo['jugadores'] === []) {
        $this->info("No hay partidos con marcador en {$anio}.");

        return;
    }

    $this->table(
        ['jugador', 'nivel de partida', 'nivel actual', 'nivel recalculado', 'cambio'],
        collect($recalculo['jugadores'])
            ->sortByDesc('nuevo')
            ->map(fn ($j) => [
                $j['nombre'],
                number_format($j['inicial'], 2),
                number_format($j['actual'], 2),
                number_format($j['nuevo'], 2),
                sprintf('%+.2f', $j['nuevo'] - $j['actual']),
            ])->values()->all(),
    );
    $this->line(count($recalculo['partidos']).' partidos repasados con k × '.$factor.'.');

    if (! $this->option('guardar')) {
        $this->comment('Vista previa: no se ha guardado nada. Repite con --guardar para aplicarlo.');

        return;
    }

    if (! $this->confirm('¿Guardar estos niveles?', true)) {
        return;
    }

    $servicio->guardar($recalculo);
    \App\Models\Auditoria::registrar('niveles.recalculados', "Niveles de {$anio} recalculados con el Elo actual (k × {$factor}).");
    $this->info('Niveles guardados.');
})->purpose('Recalcula los niveles de la temporada con el Elo actual (vista previa salvo --guardar)');

/*
 * Copia los datos de una base SQLite (la de Railway) a la conexión por
 * defecto, que debe ser Postgres (Neon) con las migraciones ya pasadas:
 *
 *   php artisan migrate --force
 *   php artisan tenis:importar-sqlite /ruta/a/database.sqlite
 *
 * Vacía antes las tablas de destino. Sesiones, caché y colas no se copian.
 */
Artisan::command('tenis:importar-sqlite {fichero : Ruta al database.sqlite de origen}', function () {
    $fichero = $this->argument('fichero');
    if (! is_file($fichero)) {
        $this->error("No existe el fichero {$fichero}.");

        return 1;
    }

    $destino = DB::connection();
    if ($destino->getDriverName() !== 'pgsql') {
        $this->error('La conexión por defecto debe ser pgsql (DB_CONNECTION=pgsql y DB_URL de Neon).');

        return 1;
    }

    config(['database.connections.origen' => [
        'driver' => 'sqlite', 'database' => $fichero, 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    $origen = DB::connection('origen');

    // Tablas con datos de verdad; lo temporal (sesiones, caché, colas) se descarta.
    $ignorar = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];
    $tablas = collect($origen->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name')
        ->diff($ignorar)
        ->values();

    // Ordeno las tablas para insertar antes las que son referenciadas por otras.
    $depende = $tablas->mapWithKeys(fn ($t) => [
        $t => collect($origen->select("PRAGMA foreign_key_list(\"{$t}\")"))->pluck('table')->intersect($tablas)->diff([$t])->values()->all(),
    ])->all();
    $orden = [];
    while (count($orden) < count($depende)) {
        $antes = count($orden);
        foreach ($depende as $t => $padres) {
            if (! in_array($t, $orden) && array_diff($padres, $orden) === []) {
                $orden[] = $t;
            }
        }
        if (count($orden) === $antes) {
            $this->error('Dependencias circulares entre tablas: '.implode(', ', array_diff(array_keys($depende), $orden)));

            return 1;
        }
    }

    $faltan = array_filter($orden, fn ($t) => ! Schema::hasTable($t));
    if ($faltan) {
        $this->error('Faltan tablas en destino (¿has pasado las migraciones?): '.implode(', ', $faltan));

        return 1;
    }

    $this->line('Tablas a copiar: '.implode(', ', $orden));
    if (! $this->confirm('Se vaciarán esas tablas en '.$destino->getDatabaseName().'. ¿Seguir?', false)) {
        return 1;
    }

    $destino->transaction(function () use ($origen, $destino, $orden) {
        $destino->statement('TRUNCATE TABLE '.implode(', ', array_map(fn ($t) => "\"{$t}\"", $orden)).' RESTART IDENTITY CASCADE');

        foreach ($orden as $tabla) {
            // SQLite guarda los booleanos como 0/1 y Postgres no los acepta así.
            $columnas = collect(Schema::getColumns($tabla))->pluck('type_name', 'name');
            $booleanas = $columnas->filter(fn ($tipo) => $tipo === 'bool')->keys()->all();

            $filas = 0;
            foreach ($origen->table($tabla)->cursor()->chunk(200) as $lote) {
                $destino->table($tabla)->insert($lote->map(function ($fila) use ($columnas, $booleanas) {
                    $fila = array_intersect_key((array) $fila, $columnas->all());
                    foreach ($booleanas as $b) {
                        if (isset($fila[$b])) {
                            $fila[$b] = (bool) $fila[$b];
                        }
                    }

                    return $fila;
                })->all());
                $filas += $lote->count();
            }

            // Que el próximo id autoincremental siga después del último copiado.
            if ($columnas->has('id')) {
                $destino->statement("SELECT setval(pg_get_serial_sequence('\"{$tabla}\"', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM \"{$tabla}\"");
            }

            $this->line(str_pad($tabla, 25).$filas.' filas');
        }
    });

    $this->info('Importación terminada.');
})->purpose('Copia los datos de un SQLite (Railway) a la base Postgres actual');
