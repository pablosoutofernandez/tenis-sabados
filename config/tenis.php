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

    // Ventanas de no repetición. Se le piden a la IA como condiciones a cumplir;
    // más allá de estas jornadas, no repetir es solo una preferencia.
    // Si la diferencia de nivel entre las dos parejas de una pista supera
    // esto, se avisa al organizador aunque la IA no haya roto ninguna norma.
    'aviso_desequilibrio_nivel' => 3,

    'no_repetir' => [
        'partidos_ultimas_jornadas' => 4,   // mismo cuarteto enfrentado
        'parejas_ultimas_jornadas'  => 4,   // misma pareja jugando junta
    ],

    // Normas del torneo que se le pasan a la IA.
    'normas' => [
        'Modalidad dobles. Se juega por sets: cada jugador suma 1 punto por set ganado, con un máximo de 3 puntos por jugador y partido.',
        'Cada sábado se juegan 2 o 3 partidos, según los jugadores disponibles.',
        'Si un jugador se retira, pierde los 3 puntos en juego pero conserva los ya ganados; su compañero suma 1 punto más si no provocó el problema, y la pareja rival se anota los 3 puntos.',
        'Faltando 12 minutos para acabar el tiempo de pista se puede jugar un super tie-break si los 4 jugadores están de acuerdo.',
        'Gana el torneo quien más puntos acumule al final del año; en caso de empate se juega un super tie-break de dobles entre los implicados.',
    ],

    // Matiz que no cubre el sistema de prioridades (ver Ajustes IA en la app):
    // equilibrio, no repetir y frenar al líder ya se gestionan aparte.
    'objetivos' => [
        'Dentro de una misma pista, evita juntar al mejor nivel con el más flojo como compañeros si hay alternativa: aunque la suma cuadre, el partido sale más disputado si los 4 niveles están relativamente cerca entre sí.',
    ],

    // Ajuste automático de nivel tras cada resultado (ver App\Services\EloNiveles).
    'elo' => [
        // Diferencia de suma de niveles entre dos parejas que predice que la
        // favorita se lleve, de media, el 91% de los sets. Más bajo = los
        // niveles deciden más antes de jugar; más alto = pesan menos.
        'divisor' => 4.0,
        // Magnitud del ajuste por pareja ante la sorpresa máxima posible.
        // Se reparte a partes iguales entre los 2 compañeros.
        'k' => 1.0,
        // Tope duro por partido, independiente de 'k' o 'divisor': nadie
        // sube ni baja más de esto en un solo partido (y, como cada
        // jugador solo juega un partido por jornada, es lo mismo que "en
        // un día").
        'tope_por_partido' => 0.5,
        // El súper tie-break sigue contando como un set más a la hora de
        // calcular quién dominó el partido, pero con menos peso que un
        // set normal (formato corto, más variable) — no se ignora del
        // todo, solo pesa menos. 1.0 = igual que un set normal, 0 = no
        // cuenta nada.
        'peso_super_tie_break' => 0.5,
    ],
];
