# Despliegue en Vercel + Neon

La app pasó de Railway (SQLite en un volumen) a Vercel (runtime `vercel-php`) con
Postgres en Neon. Vercel no tiene disco persistente, por eso la base de datos va fuera.

## Configuración en Vercel

- Application Preset: **Other** (la configuración está en `vercel.json`).
- Variables de entorno: `APP_KEY` (la de siempre, empieza por `base64:`) y `APP_URL`.
- Base de datos: Storage → Neon, región Frankfurt, prefijo `DATABASE` → crea `DATABASE_URL`.
  Sin "database branch" para Production ni Preview.

## Pasar los datos de Railway a Neon

Los datos de Railway están en una copia del `database.sqlite` (no va en Git: lleva
las contraseñas de los usuarios).

Hace falta PHP con `pdo_pgsql`. Si el PHP local no lo tiene, con Docker:

```bash
# Dockerfile de una línea para tener PHP con Postgres
docker build -t tenis-php - <<'EOF'
FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql bcmath
EOF
```

Desde la carpeta del proyecto (con `composer install` hecho), cambiando la URL y la
carpeta donde esté el `database.sqlite`:

```bash
docker run --rm -it -v "$PWD:/app" -v "/ruta/a/tenis-datos:/datos" -w /app \
  -e DB_CONNECTION=pgsql -e DATABASE_URL='postgresql://...' tenis-php \
  sh -c "php artisan migrate --force && php artisan tenis:importar-sqlite /datos/database.sqlite"
```

`tenis:importar-sqlite` vacía las tablas de destino y pide confirmación antes.
Después, en Vercel: Deployments → Redeploy.
