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
repetición era inevitable cuando no lo era). Así que `App\Services\Emparejamiento\Emparejador`
recorre **todos** los repartos posibles de los convocados (como mucho 5.775 con 3 pistas),
puntúa cada uno y se queda con el de menor coste; si varios empatan, elige entre ellos al azar.

Todo vive en `app/Services/Emparejamiento/`:

| Clase | Qué hace |
|---|---|
| `Pesos` | **Todas las escalas en un solo sitio.** Lo que vale cada ajuste del 1 al 5. |
| `ModeloCoste` | Coste de una pista, término a término (`desglose()`). |
| `NivelEfectivo` | Nivel con el que se cuadran sumas (plus del líder incluido). |
| `Repartos` | Enumera los repartos posibles. |
| `Emparejador` | Orquesta: convoca, busca, redacta y guarda. |
| `AvisosJornada` | Avisos al organizador (repeticiones recientes, pistas descompensadas). |
| `Simulador` | Temporadas ficticias para calibrar (`php artisan tenis:simular`). |

El coste de cada pista suma, en una única unidad (diferencia de suma de nivel al cuadrado):
- **Equilibrio**: `peso × diferencia²` entre las sumas de nivel efectivo de las dos parejas.
- **Parejas repetidas**: cada vez que esos dos ya fueron pareja esta temporada, más cuanto
  más reciente (100% la semana pasada, 54% hace 2, 32% hace 3… nunca menos del 10%).
- **Rivales repetidos**: cada cruce que ya se dio; casi solo cuenta la semana pasada (6% a
  las 2 semanas en "normal", 24% en "máxima").

En "normal", repetir la pareja de la semana pasada equivale a aguantar 3 puntos de
diferencia de suma, y repetir un cruce, unos 2,3. Calibrado para un grupo con niveles ~3-8.

El admin ve en "Montar jornada" un **Detalle técnico del cálculo** con los pesos en vigor,
el nivel efectivo de cada uno, el coste de cada pista sumando término a término y las 5
mejores alternativas.

Para calibrar sin tocar datos reales (usa una base en memoria):

```bash
php artisan tenis:simular                 # ajustes por defecto
php artisan tenis:simular --lider=5       # un escenario concreto
php artisan tenis:simular --barrido       # cada ajuste de 1 a 5
```

### Frenar al líder

A quien va primero en puntos entre los convocados de hoy se le suma un plus a su nivel antes
de calcular nada. El plus es proporcional a la ventaja que le saca al segundo y llega al máximo
del ajuste con 3 puntos (un partido) de ventaja; con empate en cabeza no hay plus. Los refuerzos
nunca son líderes. El algoritmo solo ve un nivel más alto y, al cuadrar sumas, le da una pareja
más floja o rivales más fuertes; no hay ninguna regla que le impida jugar con el más flojo.

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

La idea: compara cada set con lo que "tocaba" según la diferencia de suma de nivel, y mueve el
nivel de los 4 según la sorpresa total. **Cada set es una prueba aparte** (el súper tie-break
con medio peso): en cada uno cuenta a medias el % de juegos (un 6-0 no es un 7-6) y haberlo
ganado. Las sorpresas de todos los sets se suman, así que un 4-0 (3-0 + súper) mueve casi el
doble que un 2-0, y un 2-1 mueve lo ganado menos lo perdido. Si todo sale como se esperaba, no
se mueve nada.

```php
$esperadoSet = ½ · 1/(1 + 10^(−dif/10))    // % de juegos: +2 → 61%, +4 → 72%
             + ½ · 1/(1 + 10^(−dif/4));    // ganar el set: +2 → 76%, +4 → 91%
$sorpresa    = Σ peso · (½ · %juegos_set + ½ · ganado_set − $esperadoSet);
$delta       = $k * $sorpresa / 2;         // por jugador, con tope ±1 por partido
```

**k (por set) depende de la experiencia de cada jugador esta temporada**: 1,5 en sus 8 primeros
partidos (periodo provisional) y 0,4 después. Ejemplos entre parejas iguales (pareja ganadora;
los puntos del torneo siguen topados en 3):

| Resultado | Sets | Provisional | Normal |
|---|---|---|---|
| 6-4 6-4 | 2-0 | +0,45 | +0,12 |
| 6-4 6-4 6-4 | 3-0 | +0,68 | +0,18 |
| 6-4 6-4 6-4 + súper 10-5 | 4-0 | +0,80 | +0,21 |
| 6-4 3-6 6-4 | 2-1 | +0,20 | +0,05 |
| 6-4 4-6 + súper 10-5 | 2-1 | +0,13 | +0,03 |
| 7-6 7-6 0-6 | 2-1 | +0,03 | +0,01 |
| 6-4 4-6 6-4 + súper 8-10 | 2-2 | +0,12 | +0,03 |

Todo se ajusta en `config/tenis.php` → `elo`. En simulaciones de 30 jornadas empezando todos en
5,0, este esquema reduce el error de nivel a menos de la mitad que el antiguo, y una vez el
nivel es correcto lo mueve poco. Más casos en `tests/Feature/ResultadosTest.php`.

Puntos a tener en cuenta:
- **El ajuste es igual para los dos compañeros** salvo que uno esté en periodo provisional y el
  otro no; no hay forma de saber quién ganó qué punto dentro del partido.
- **Los partidos con retirada no mueven nivel**: el marcador no refleja mérito real.
- **Sin tope superior ni inferior** en el nivel; el tope es por partido.
- **Corregir o borrar un resultado revierte el ajuste** de ese partido antes de aplicar el nuevo
  (se guarda en `partido_jugador.nivel_delta`).
- **Recalcular la temporada** con los parámetros actuales (vista previa; solo guarda con `--guardar`):

  ```bash
  php artisan tenis:recalcular-niveles
  php artisan tenis:recalcular-niveles --guardar
  ```

- Sigue pudiendo **corregirse el nivel a mano** en cualquier momento desde Jugadores.

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