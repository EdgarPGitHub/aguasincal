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
data/         municipios.csv (INE + IGN) y sinac.jsonl (datos del agua por red, SINAC)
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
php build/build.php --noindex              # vista previa: visible pero fuera de Google
TELEFONO="600 12 34 56" WHATSAPP="600 12 34 56" php build/build.php   # con botones de contacto
php -S 127.0.0.1:8080 -t dist scripts/servidor-local.php   # servir la web (panel en /gestion/)
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

`.github/workflows/deploy.yml` publica al fusionar en `main` o con **Run workflow**:

1. tests;
2. generar la web;
3. subir por **FTPS** (`scripts/desplegar-ftp.sh`, con `lftp`);
4. comprobaciones HTTP.

- No necesita SSH ni cron: la clave de la app (`app.key`), las migraciones, el primer usuario del panel y las tareas diarias (`app/src/Mantenimiento.php`) los resuelve la propia web.
- Al fusionar en `main` solo publica con la variable `DESPLIEGUE_ACTIVO=si`; con **Run workflow** publica siempre. Basta con los secretos del FTP: sin los del panel, el panel queda cerrado; sin `SMTP_PASS`, los emails se guardan en el servidor.
- `INDEXAR=no` publica en modo vista previa (noindex).
- Teléfono y WhatsApp salen de las variables `TELEFONO` y `WHATSAPP`.

Los secretos y variables necesarios están en [docs/PASOS.md](docs/PASOS.md).

## Datos

- `scripts/semillas_keywords.php` y `scripts/dataforseo.php`: estudio de keywords y posiciones (necesita `DATAFORSEO_LOGIN` y `DATAFORSEO_PASSWORD`).
- `scripts/municipios.php`: genera `data/municipios.csv` (padrón del INE y centro de cada municipio con límites del IGN). Una vez al año.
- `scripts/sinac.php`: descarga del SINAC los datos del agua de cada municipio a `data/sinac.jsonl`. Lo hace sola la tarea semanal de GitHub (`datos-agua.yml`, 3 partes en paralelo).
- `data/empresas.csv` (opcional): fichas del directorio añadidas por nosotros. La web las importa sola al cambiar el archivo y nunca pisa lo que haya cambiado la empresa.

## Vídeos explicativos

Animaciones propias con voz, montadas en local (no en GitHub Actions) y publicadas como MP4 H.264 + AAC en `assets/videos/`, con portada JPG, subtítulos VTT, página propia en `/videos/…/` con transcripción y datos estructurados `VideoObject`. Todo gratis y sin servicios externos.

- `videos/<nombre>/guion.json`: textos (cada frase es un subtítulo), ruta, páginas relacionadas y voz.
- `videos/<nombre>/escenas.html`: el dibujo (SVG) de cada escena, sincronizado con las frases. Piezas comunes en `videos/piezas.js` y `videos/motor.js`.
- Una página incrusta un vídeo con `video: <nombre>` en su front matter.

```bash
pip install piper-tts soundfile        # una vez; voz es_ES-sharvard-medium en var/videos/modelos/
python3 scripts/video-voz.py videos/descalcificador                  # narración y tiempos
node scripts/video-render.js videos/descalcificador --fotos=10,30    # capturas para revisar
node scripts/video-render.js videos/descalcificador                  # MP4, portada, subtítulos y video.json
```

Para que suene natural: coma tras conectores («Por eso, …»), « | » para una pausa de respiración que no sale en los subtítulos y `{"texto": "…", "voz": "…"}` para palabras que se leen mal.

## Directorio de empresas

Páginas PHP (`app/src/Controladores/Empresas.php`): `/empresas/`, `/empresas/{provincia}/{municipio}/`, `/empresa/{slug}/`, alta, acceso con enlace por email y `sitemap-empresas.xml`. Cada empresa es una fila de `profesionales` con ficha pública (`app/src/Directorio.php`), así que también puede recibir leads. Orden: destacadas vigentes primero y luego por cercanía, dentro del radio de cada empresa.
- Escala de dureza y supuestos de cálculo: `app/src/Agua.php`, replicados en `assets/js/app.js`.
