# AguaSinCal.es

Web de captación de presupuestos de tratamiento de agua (descalcificadores, ósmosis inversa, filtros, fuentes de agua y mantenimiento) con datos oficiales de dureza del agua por municipio.

- **Web pública:** HTML estático generado con PHP + Twig. Rápida, segura y fácil de migrar.
- **PHP solo en tres sitios:** el formulario de presupuesto (`/presupuesto/`), las acciones de los emails (`/accion/`) y el panel de gestión.
- **Base de datos:** SQLite en un único archivo fuera de la carpeta pública (`~/aguasincal-data/aguasincal.sqlite`).

Qué falta por hacer y qué se te pedirá en cada paso: [docs/PASOS.md](docs/PASOS.md).

## Estructura

```
config/       datos del sitio, servicios, provincias, zonas publicadas, precios, redirecciones
content/      textos en Markdown con front matter: paginas/, servicios/, ciudades/, guias/
data/         municipios.csv (INE) y agua.csv (SINAC) normalizados
templates/    plantillas Twig: web, formulario, panel y emails
assets/       css/estilos.css y js/app.js (sin dependencias externas)
build/        generador de la web estática y comprobaciones SEO
app/          código PHP del formulario, las acciones y el panel (app/src) y sus puntos de entrada (app/public)
migrations/   esquema SQL
scripts/      administración, tarea diaria, DataForSEO e importación de datos
tests/        PHPUnit (+ tests/fixtures con datos FICTICIOS)
```

## Trabajar en local

```bash
composer install
vendor/bin/phpunit                         # tests
php build/build.php                        # genera dist/ con los datos reales
php build/build.php --datos=tests/fixtures/data --hoy=2026-10-05   # con datos de prueba
php -S 127.0.0.1:8080 -t dist              # servir la web (el panel queda en /gestion/)
```

En local, la configuración está en `var/config.local.php` (no se sube a git) y los emails se guardan como archivos en `var/correo/`.

Crear un usuario del panel en local:

```bash
ADMIN_CLAVE='una-clave-larga-de-prueba' php scripts/admin.php crear-usuario tu@email.es
```

## Contenido

Cada página es un Markdown con front matter. Campos obligatorios: `titulo`, `descripcion`, `ruta`, `estado` (`borrador` | `publicado`) y `actualizado`.

- Las páginas en **borrador** se generan con `noindex` y no van al sitemap.
- Las de servicios, ciudades y guías solo se publican si tienen **revisor técnico**: `revision: tecnica` usa el revisor definido en `config/site.php`.
- Marcadores como `%titular.nombre%` se sustituyen con `config/site.php`. Si falta el dato, la página queda en borrador.

## Comprobaciones que bloquean el despliegue

El generador falla (código de salida 1) si encuentra:

- rutas duplicadas o enlaces internos rotos;
- páginas indexables sin title, description, canonical o con más de un H1;
- JSON-LD no válido;
- un sitemap que no coincide con las páginas indexables;
- textos redactados casi duplicados (más del 60 %).

## Despliegue

`.github/workflows/deploy.yml` despliega al fusionar en `main`: tests → generar → rsync por SSH a Raiola → migraciones → comprobaciones. Los secretos necesarios están en [docs/PASOS.md](docs/PASOS.md).

## Datos

- `scripts/semillas_keywords.php` y `scripts/dataforseo.php`: estudio de keywords y posiciones (necesita `DATAFORSEO_LOGIN` y `DATAFORSEO_PASSWORD`).
- `scripts/importar_agua.php`: normaliza datos de calidad del agua al formato de `data/agua.csv`.
- Escala de dureza y supuestos de cálculo: `app/src/Agua.php`, replicados en `assets/js/app.js`.
