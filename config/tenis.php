<?php

return [

    'temporada' => [
        'nombre'  => 'XXIII Torneo anual de Tenis Sábados',
        'inicio'  => '2026-02-21',
        'fin'     => '2026-11-28',
        'ciudad'  => 'Vigo',
    ],

    // Puntuación: 1 punto por set ganado, máximo 3 por jugador y partido.
    'puntos_max_por_partido' => 3,
    'jugadores_por_pista'    => 4,

    // Cada sábado se juegan 2 o 3 partidos de dobles.
    'pistas_min' => 2,
    'pistas_max' => 3,

    // Escala de la tabla de clasificación (columnas de puntos). Esto es
    // solo el MÍNIMO — si el líder tiene más, la escala sube sola hasta
    // el siguiente múltiplo de 10 (ver Clasificacion::render()). No lo
    // subas mucho: un mínimo alto deja huecos vacíos al principio de
    // temporada, cuando nadie ha llegado ahí todavía.
    'escala_clasificacion'   => 30,

    // Si la diferencia de suma de nivel entre las dos parejas de una pista
    // llega a esto, se avisa al organizador al revisar la propuesta.
    'aviso_desequilibrio_nivel' => 3,

    // Hasta cuántas jornadas atrás AVISAN las repeticiones al revisar una
    // propuesta (no cambian el reparto: los pesos están en
    // App\Services\Emparejamiento\Pesos). Cada una la estira o encoge su
    // ajuste de la app. La de rivales es 1 porque el reparto solo intenta
    // de verdad no repetir el cruce de la semana pasada.
    'no_repetir' => [
        'partidos_ultimas_jornadas' => 4,   // mismo cuarteto enfrentado
        'parejas_ultimas_jornadas'  => 4,   // misma pareja jugando junta
        'rivales_ultimas_jornadas'  => 1,   // mismos dos jugadores enfrentados (solo importa la semana pasada)
    ],

    // Normas del torneo (se muestran en la clasificación).
    'normas' => [
        'Modalidad dobles. Se juega por sets: cada jugador suma 1 punto por set ganado, con un máximo de 3 puntos por jugador y partido.',
        'Cada sábado se juegan 2 o 3 partidos, según los jugadores disponibles.',
        'Si un jugador se retira, pierde los 3 puntos en juego pero conserva los ya ganados; su compañero suma 1 punto más si no provocó el problema, y la pareja rival se anota los 3 puntos.',
        'Faltando 12 minutos para acabar el tiempo de pista se puede jugar un super tie-break si los 4 jugadores están de acuerdo.',
        'Gana el torneo quien más puntos acumule al final del año; en caso de empate se juega un super tie-break de dobles entre los implicados.',
    ],

    // Ajuste automático de nivel tras cada resultado (ver App\Services\EloNiveles).
    'elo' => [
        // Lo esperado en cada set (ver EloNiveles::sorpresa): ½ % de juegos
        // (con +2 de suma ~61%, +4 ~72%) y ½ prob. de ganarlo (+2 → 76%,
        // +4 → 91%).
        'divisor_juegos' => 10.0,
        'divisor_sets'   => 4.0,
        // Cuánto se mueve cada jugador: k × Σ sorpresa de cada set / 2. Es
        // POR SET: un 4-0 mueve el doble que un 2-0. Calibrado con
        // temporadas simuladas; ejemplos en tests/Feature/ResultadosTest.php.
        'k' => 0.4,
        // Periodo provisional: en sus primeros N partidos de la temporada
        // aún no sabemos el nivel de alguien, así que se mueve más rápido.
        'k_provisional'        => 1.5,
        'partidos_provisional' => 8,
        // Tope duro por partido y jugador, sea cual sea k.
        'tope_por_partido' => 1.0,
        // El súper tie-break sigue contando como un set más a la hora de
        // calcular quién dominó el partido, pero con menos peso que un
        // set normal (formato corto, más variable) — no se ignora del
        // todo, solo pesa menos. 1.0 = igual que un set normal, 0 = no
        // cuenta nada.
        'peso_super_tie_break' => 0.5,
    ],
];
