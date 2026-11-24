# Historial y decisiones (sesión en la nube, oct–nov 2026)

Resumen de lo hablado que no está en el código ni en [PLAN.md](PLAN.md) / [PASOS.md](PASOS.md), para retomar el trabajo
en local sin la conversación original. Lo más reciente manda sobre lo anterior.

## Cómo quiere trabajar Edgar

- **Simplicidad, fiabilidad y seguridad por encima de todo.** «Es mejor algo sencillo que funcione que algo demasiado
  complejo que, al fallar, dejemos de atender». Cuanto menos tenga que estar encima, mejor: que todo funcione solo.
- Prioridades: SEO, experiencia de usuario, utilidad. Diseño sobrio que **no parezca hecho con IA**; Claude se centra en
  funcionalidad y utilidad.
- Las preguntas, al principio o al final de cada bloque de trabajo, no en medio.
- **Nunca contraseñas, claves ni tokens por el chat**: van a los Secrets de GitHub o a los ajustes del entorno.
- Trabajo en local: `C:\Users\Dager\Documents\GitHub\aguasincal` (clonado con GitHub Desktop, Windows).
- Rama de trabajo de la sesión en la nube: `claude/bold-turing-l5nm4x` (igual que `main`). En local se puede trabajar
  directamente en `main`.

## Estado a 24-nov-2026

- Web publicada en https://aguasincal.es en **vista previa (noindex)**: variable de GitHub `INDEXAR` sin crear (= `no`).
- 1.429 municipios con datos del SINAC (1.299 páginas de municipio indexables), directorio de empresas vacío, 10 guías,
  6 servicios, 1 vídeo.
- Los datos del SINAC se actualizan solos cada lunes (workflow «Datos del agua»), pero **no se publican solos** hasta
  crear la variable `DESPLIEGUE_ACTIVO=si`.
- Hosting Raiola: `FTP_HOST = com1014.raiolanetworks.es` (el nombre del certificado; `ftp.aguasincal.es` no vale).
  DNS en Raiola. Correo del dominio **bien configurado** (MX, SPF, DKIM y DMARC con `p=quarantine`), comprobado con el
  workflow «Comprobar servidor».

## Pendiente, en este orden

1. **Secretos de GitHub** (Settings → Secrets and variables → Actions → Secrets): `SMTP_PASS` (contraseña del buzón
   info@aguasincal.es, crearlo en cPanel si no existe), `ADMIN_EMAIL`, `ADMIN_CLAVE` (≥ 12 caracteres) y `PANEL_RUTA`
   (minúsculas, números y guiones). Hoy faltan los cuatro: el panel está cerrado y los emails se guardan en el servidor
   (`aguasincal-data/correo/`) en vez de enviarse.
2. Publicar (Actions → Despliegue → Run workflow) y hacer una **solicitud de presupuesto de prueba**: comprobar que llega
   el email y que se ve en el panel.
3. Variables: `INDEXAR=si` y `DESPLIEGUE_ACTIVO=si`, y publicar. El despliegue **se niega a lanzar** con `INDEXAR=si` si
   faltan los secretos del punto 1, y comprueba que desaparece el noindex y que robots.txt enlaza el sitemap.
4. Search Console (propiedad de dominio con registro TXT en el DNS de Raiola) y enviar `/sitemap.xml`. Luego Bing.
5. Vídeo de la ósmosis inversa (mismo estilo y voz), después fuentes de agua osmotizada.
6. Cargar empresas en el directorio (ver más abajo).
7. Guías nuevas según [ideas-webs-referencia.md](ideas-webs-referencia.md) y el estudio de keywords.
8. Imágenes: mapa de España de dureza con los datos (se actualiza solo), mapas por provincia, esquemas para las guías.
9. Precio y alcance de la ficha destacada (sin decidir; propuesta abajo).

## Decisiones tomadas

- **Vista previa:** publicar sin contraseña pero con noindex hasta que lleguen los leads.
- **Autoría:** «Equipo AguaSinCal» (sin técnico con nombre de momento).
- **Noticias automáticas:** aplazadas.
- **Directorio:**
  - Cuatro tipos de ficha con el mismo recorrido y alta: automática (añadida por nosotros), creada por la empresa,
    patrocinada (destacada) y las propias de Edgar (también se da de alta como profesional).
  - Coordenadas por municipio; al buscar una población salen las de ese municipio y luego las cercanas por distancia.
  - Fichas automáticas: **algunas empresas intermedias** (no las mejores) en las poblaciones importantes; el resto de
    poblaciones, vacías salvo los profesionales propios. Se les puede escribir para avisar de que están.
  - **Destacadas (propuesta, sin cerrar):** aparecen arriba dentro de su radio de servicio; precio mensual fijo, cobro
    manual y caducidad por fecha (`destacada_hasta`), que ya funciona.
- **Correo:** un solo buzón, info@aguasincal.es, para todo (envío de la web y contacto). Sin envíos masivos: escribir a
  empresas en tandas pequeñas y personalizadas (10–20 al día). Si algún día se escribe a cientos, usar otra dirección.
- **Menú móvil:** pantalla completa sin scroll de página, opciones arriba, botón de presupuesto abajo, icono X y texto
  «Cerrar» (como webcamscostarica.com). Hecho.

## Vídeos

- Proceso en el README («Vídeos explicativos»): `scripts/video-voz.py` (voz y tiempos) y `scripts/video-render.js`
  (fotogramas con Playwright → MP4 con ffmpeg, portada, subtítulos y `video.json`). Todo gratis y en local.
- **Formato SEO:** MP4 H.264 + AAC alojado en el propio dominio, página propia por vídeo (Google solo muestra miniatura
  de vídeo cuando el vídeo es lo principal de la página), datos VideoObject, portada, subtítulos y transcripción.
  También se incrusta en la página del servicio (`video: <nombre>` en el front matter). YouTube, opcional más adelante.
- **Imágenes:** para dibujos, esquemas y la imagen al compartir, formato raster (WebP en página, JPG para og:image);
  SVG solo para iconos y logo.
- **Voz elegida:** Piper, voz `es_ES-sharvard-medium`, hablante 0 (hombre), ajustes «D»: `lentitud 1.07`, `ritmo 0.7`,
  pausa en comas de 0,22 s, unión de palabras solo ante vocal tónica. Licencia CC BY 3.0 (se cita en la página).
  Descartadas: Kokoro y Qwen3-TTS (a Edgar no le gustaron) y las voces de pago.
- **Reglas para escribir el guion** (aprendidas con las pruebas de Edgar):
  - Nada de preguntas al principio: la entonación de pregunta suena mal. Mejor «Te explicamos cómo funciona…».
  - Coma tras conectores («Por eso, …», «Y así, …»).
  - No dejar trozos cortos sueltos tras una coma o pausa («… | de vez en cuando» suena a frase nueva).
  - Evitar expresiones que suenan raras: «de vez en cuando» → «cada cierto tiempo»; «a la entrada de la casa» →
    «justo donde entra el agua en casa».
  - El dominio se pronuncia «aguasincal punto es» y mejor con contexto: «en nuestra web, aguasincal punto es».
  - El script ya corrige la «ü» (desagüe) y quita el acento al artículo «un».
- Vídeo del descalcificador: 1:15, publicado en `/videos/como-funciona-un-descalcificador/` y en `/descalcificadores/`.

## Keywords (DataForSEO, nov-2026)

Datos en `docs/keywords/` (volumen de 836 semillas e ideas relevantes). Destacan: «descalcificador» 8.100/mes,
«ósmosis inversa» 8.100, «descalcificador sin sal» 1.000, «sal descalcificador» 880, «dureza del agua» 720,
«osmosis inversa precio» 590, «cambiar filtros osmosis» 480, «como funciona un descalcificador» 170.
Oportunidades sin página: «dispensador de agua» 12.100, «filtro de agua para grifo» 6.600, «fuente de agua filtrada y
fría» 4.400, «descalcificador magnético» 140, «filtro antical termo» 90, «cambio resina descalcificador» 70.

DataForSEO: saldo ~47 $ el 13-nov. En la nube la credencial la ponía el entorno; **en local hay que definir**
`DATAFORSEO_LOGIN` y `DATAFORSEO_PASSWORD` (la «API password» de app.dataforseo.com/api-access, no la de la web).

## Empresas para el directorio

- `php scripts/dataforseo.php empresas 50000` sacó 109 candidatas en 40 ciudades de 50.000+ habitantes de las
  provincias prioritarias (sin las 2 primeras de cada ciudad, nota ≥ 4). Se descartaron 29 (piscinas, fosas sépticas,
  plagas, fontanerías, duplicadas): quedan 80.
- Archivos **solo en local**, en `var/` (no se suben porque el repositorio es público): `empresas-candidatas.csv`
  (todas), `empresas-filtradas.csv` (las 80) y `empresas-propuesta.csv` (las 80 en el formato de `data/empresas.csv`).
- Para cargarlas: revisar la propuesta, copiar las elegidas a `data/empresas.csv` y publicar (se importan solas, sin
  pisar los cambios que haga luego cada empresa). Sin descripción la ficha sale en los listados pero no se indexa.
- Ojo: satdescalcificadores.es, aqura.es, descalcificadoresterrassa.es y descalcificadoresprom.es son **la misma
  empresa** (Aqura, Mollet del Vallès): detectar duplicados por teléfono o razón social.

## Lecciones técnicas

- SINAC: hace falta sesión (cookies) para ver el detalle; redes grandes tardan hasta 5 min (timeout 420 s); algunas
  empresas publican la dureza en °fH etiquetada como mg/L (se detecta comparando con calcio y magnesio). Solo se usan
  muestras de los últimos 5 años; las páginas de municipio, solo con muestras de los últimos 24 meses y 1.000+ habitantes.
- El despliegue sube la web entera por FTP (~10 min). Durante la subida alguna página puede cargar un momento sin
  CSS/JS. Mejora posible: subir solo lo que cambia.
- Títulos de municipio: máximo 65 caracteres; con nombres bilingües largos se usa la forma castellana.
- Provincias con menos de 5 municipios con datos quedan en noindex (enlazarlas no perjudica).
