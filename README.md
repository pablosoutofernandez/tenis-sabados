# Tenis Sábados — emparejamientos de dobles

Proyecto Laravel 12 + Livewire 3, listo para instalar dependencias y arrancar en local.
SQLite ya configurado (`database/database.sqlite`), sin necesidad de MySQL ni Docker.

## Arrancar en local

Desde la carpeta del proyecto:

```bash
composer install
npm install
```

Migra y siembra los 17 jugadores del cartel:

```bash
php artisan migrate
php artisan db:seed
```

Compila los estilos y levanta el servidor (en dos terminales, o con `composer run dev` que
lanza ambos a la vez):

```bash
npm run dev
php artisan serve
```

Entra en `http://localhost:8000`. Verás la Clasificación directamente — no hay login
configurado todavía (ver más abajo).

## Qué hace

- **Jugadores** (`/jugadores`): alta/edición con nivel (1–10). Es lo único que usa el algoritmo para equilibrar.
- **Montar jornada** (`/jornada`): marcas quién está disponible ese sábado y el algoritmo reparte 2 o 3 partidos de dobles.
- **Clasificación** (`/clasificacion`): la cuadrícula del cartel — cada punto es un set ganado, máximo 3 por jugador y partido. Desde aquí también se puede fijar una puntuación de partida (independiente de los partidos jugados en la app).
- **Historial** (`/historial`): cada jornada con sus parejas y sets.
- **Ajustes** (`/ajustes`): los pesos que usa el algoritmo — variedad, equilibrio, frenar al líder.
- **Usuarios** (`/usuarios`, solo admin): activar cuentas, roles y vínculo con la ficha de jugador.
- **Actividad** (`/actividad`, solo admin): quién ha tocado qué.

## Quién decide los emparejamientos

**Un algoritmo determinista, no un modelo de lenguaje.** Repartir N jugadores en parejas
minimizando repeticiones y maximizando equilibrio es un problema de optimización combinatoria;
pedírselo a una IA generativa en una sola pasada de texto daba fallos sistemáticos (se quedaba
con la combinación más "obvia" en vez de explorar alternativas, llegaba a decir que una
repetición era inevitable cuando no lo era). Así que `App\Services\EmparejadorIA::calcularAsignacion()`
prueba entre 400 y 3.000 repartos al azar, puntúa cada uno y se queda con el de menor coste —
si varios empatan, elige entre ellos al azar, para no converger siempre a la misma solución.

El coste de cada pista suma:
- Coste alto si repite una pareja o cuarteto dentro de la ventana vetada (`config/tenis.php` → `no_repetir`).
- Coste creciente según cuántas veces esas combinaciones ya se han dado esta temporada, aunque
  queden fuera de la ventana — pesa más ser pareja que ser solo rival.
- Coste al cuadrado por cada punto de diferencia de nivel que pase del umbral tolerado.
- Un matiz de peso fijo: evita juntar al mejor nivel con el más flojo como compañeros aunque la suma cuadre.

Los pesos de "cuánto pesa cada cosa" se controlan desde **Ajustes IA** (`App\Models\AjustesIA`,
tabla `ajustes_ia`, una sola fila) y están documentados con detalle en esa misma página —
incluida la respuesta a "¿se puede quedar atascado repitiendo siempre lo mismo?".

### Frenar al líder

No es una instrucción de texto: a quien va primero en puntos entre los disponibles de hoy se le
suma un plus fijo a su nivel antes de calcular nada (`nivelesEfectivos()`). El algoritmo no sabe
que existe "frenar al líder"; solo ve un nivel más alto, y al intentar equilibrar sumas acaba
dándole una pareja floja o un rival fuerte. En el slider al mínimo, el plus es 0.

### Corregir un partido a mano

En "Montar jornada", cada pista sin resultado tiene un botón "Corregir" que deja tocar a
cualquiera de los 4 jugadores para pasarlo a la otra pareja. Es una anulación puntual del
organizador — el algoritmo no lee nada de eso para futuras jornadas, así que si algo debe
respetarse siempre, ajusta los pesos en Ajustes IA en vez de depender de ediciones manuales
repetidas.

## Cuentas y roles

Tres roles:

| Rol | Qué puede hacer |
|---|---|
| **Administrador** | Todo: usuarios, jugadores, ajustes, jornadas, resultados y registro de actividad. |
| **Organizador** | Monta jornadas y anota resultados, pero **no ve la clasificación ni los puntos de nadie** — reparte "a ciegas". |
| **Jugador** | Ve la clasificación y el historial, y publica el resultado **solo de los partidos que juega él**. |

### Primer arranque

```bash
php artisan migrate
php artisan db:seed
```

Crea los 17 jugadores del cartel y la cuenta de administrador:

- **Nombre:** `Pablo` (configurable con `ADMIN_NOMBRE`)
- **Contraseña inicial:** `contraseña` (configurable con `ADMIN_PASSWORD`)

Está marcada para **cambio obligatorio**: al entrar por primera vez, la app no deja hacer nada
más hasta cambiarla. La contraseña se guarda siempre hasheada (bcrypt, vía el cast `hashed`).
Si ya existe un jugador llamado "Pablo" en el torneo, la cuenta se vincula con él sola.

### Cómo se incorpora alguien

1. La persona se registra en `/registro`.
2. La cuenta se crea **inactiva**: sin que el admin la apruebe no entra nadie, aunque conozca la URL.
3. El admin, en **Usuarios**, la activa, le da rol y la **vincula con su ficha de jugador** del torneo.
4. Sin ese vínculo, un jugador no puede publicar resultados: la app no sabe qué partidos son suyos.

Desde **Usuarios** el admin también puede cambiar roles, desvincular, desactivar cuentas, generar
una **contraseña temporal** (se muestra una sola vez y obliga a cambiarla al entrar) y eliminar
cuentas. No puede desactivarse, cambiarse el rol ni borrarse a sí mismo, para no quedarse fuera.

### Registro de actividad

En **Actividad** (solo admin) queda constancia de quién ha hecho qué, con fecha y hora: resultados
guardados, corregidos y borrados, correcciones manuales de partidos, jornadas generadas,
publicadas y eliminadas, altas/bajas/cambios de nivel de jugadores, ajustes del algoritmo,
puntuaciones ajustadas a mano y cambios en las cuentas. Se guarda el nombre del usuario además
de su id, así que si una cuenta se borra el registro sigue diciendo quién fue.

## Cómo se ajusta el nivel: sistema de Elo

No hay ninguna IA en la app — el reparto es un algoritmo determinista (arriba) y el nivel de
cada jugador se ajusta solo, tras cada resultado, con `App\Services\EloNiveles`.

La idea: compara la suma de nivel de una pareja contra la otra, calcula qué resultado "tocaba"
según esa diferencia (con la misma fórmula logística que usa el ajedrez, adaptada a esta escala),
y mueve el nivel de los 4 jugadores según lo lejos que quedó el resultado real de lo esperado.
Ganar como se esperaba apenas mueve nada; ganar cuando no tocaba (o perder por poco siendo muy
superior) mueve bastante más.

```php
$esperadoA = 1 / (1 + 10 ** (($sumaB - $sumaA) / $divisor));   // % de sets que "tocaba" ganar
$realA     = $setsA / ($setsA + $setsB);                        // % de sets que ganó de verdad
$delta     = $k * ($realA - $esperadoA);                        // se reparte entre los 2 de la pareja
```

`divisor` y `k` se ajustan en `config/tenis.php` → `elo`. Con los valores por defecto, un
resultado dentro de lo esperado mueve ~0.05 por jugador; una sorpresa grande puede mover ~0.4-0.5.

Puntos a tener en cuenta:
- **El ajuste se reparte a partes iguales** entre los 2 compañeros de cada pareja — no hay forma
  de saber quién ganó qué punto dentro del partido, así que no se intenta repartir de otra forma.
- **Los partidos con retirada no mueven nivel**: el marcador no refleja mérito real (el rival se
  lleva los 3 puntos por norma, no por juego).
- **Sin tope superior ni inferior**: si alguien juega sistemáticamente por encima de su nivel,
  puede superar el 10 sin límite — nunca fue un techo real, solo el valor por defecto del
  formulario. Por eso el campo de nivel en Jugadores es un número normal, no un slider: un
  slider necesita un máximo fijo, y aquí no lo hay.
- **Corregir o borrar un resultado revierte el ajuste** que se había aplicado por ese partido en
  concreto (se guarda en `partido_jugador.nivel_delta` para poder deshacerlo).
- **Empieza a contar desde el primer resultado que guardes** con este sistema activo; no
  recalcula nada de lo ya jugado antes.
- Sigue pudiendo **corregirse el nivel a mano** en cualquier momento desde Jugadores — el Elo no
  bloquea la edición manual, solo la complementa.

## Sin IA, en ningún sitio

Antes había una IA generativa que revisaba resultados y sugería cambios de nivel
("Revisar niveles con IA"). Se quitó: el ajuste automático de arriba cubre lo mismo sin
necesidad de llamar a ninguna API. El proyecto ya no necesita `ANTHROPIC_API_KEY` para nada —
si la tienes puesta en el `.env`, puedes quitarla sin que se rompa nada.

## Cómo se monta una jornada

1. Marcas los disponibles del sábado. Las pistas salen solas: 4 jugadores por partido, máximo 3 (`config/tenis.php` → `pistas_max`).
2. Si los disponibles no son múltiplo de 4, los que sobran quedan en "sin jugar" — con prioridad a jugar para quien menos partidos lleva esta temporada.
3. El algoritmo calcula la asignación (ver arriba) y redacta un motivo por pista y una explicación general a partir de los números reales, no inventados.

Lo que se valida en firme (y hace fallar la generación si no se cumple): número de partidos,
parejas de dos jugadores, nadie repetido y ningún disponible sin sitio. Por construcción, el
algoritmo siempre debería cumplirlo — es una red de seguridad barata, no algo que se espere que falle.

## Reparto de puntos

`Partido::registrarResultado($setsA, $setsB, $retiradoId)` aplica la norma tal cual: 1 punto por
set ganado con tope de 3; si alguien se retira conserva los sets ya ganados, su compañero suma 1
punto extra y la pareja rival se lleva los 3 puntos.

Los puntos nunca se guardan en la tabla de jugadores: la clasificación se calcula sumando el
pivote `partido_jugador` más el ajuste manual de `ajustes_puntos`, así que corregir o borrar un
resultado la deja al día sola.

## Ideas para después

- Vetos explícitos y estructurados (checkbox "estos dos no deben ser pareja") en vez de depender
  de ediciones manuales repetidas para algo que debiera ser permanente.
- Aviso automático jueves/viernes con las parejas del sábado.
- Comando `php artisan tenis:jornada` para generarla por schedule.
- Registro de la cuota mensual de 8 € y del reparto de pistas.
#   t e n i s - s a b a d o s  
 #   t e n i s - s a b a d o s  
 