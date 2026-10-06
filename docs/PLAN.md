# Plan AguaSinCal.es: web de captación de leads de tratamiento de agua

> Plan aprobado el 5-oct-2026. Cambios posteriores: vista previa sin contraseña (noindex), teléfono y WhatsApp como variables de GitHub, un solo buzón (info@) y clave de la app generada en el servidor. Los pasos vigentes están en [PASOS.md](PASOS.md).

## Contexto

- **Dominio:** aguasincal.es. Hay un WordPress de prueba que se sustituye por una web propia en PHP en el hosting compartido de Raiola (cPanel).
- **Modelo de negocio:** vender leads (solicitudes de presupuesto) a profesionales colaboradores. Todos los leads pasan primero por ti, por formulario o por tu teléfono/WhatsApp. Nunca se muestra el contacto directo del profesional.
- **Profesional actual:** cubre Barcelona, Girona, Lleida, Tarragona y Málaga (provincias). Hace:
  - Descalcificadores para particulares y empresas.
  - Ósmosis en venta y alquiler, para particulares y hostelería (restaurantes, hoteles, bares).
  - Filtros de agua.
  - Fuentes y dispensadores de agua osmotizada en venta y alquiler (oficinas, talleres, consultas, gimnasios…).
  - Mantenimiento y reparación.
- **Comunidades de vecinos:** él no las atiende. Siempre en lista de espera.
- **Resto de España:** sin profesional. Se recogen leads honestos en lista de espera. Cuando se acumulan en una provincia, buscas profesional ahí, una zona cada vez.
- **Lo que pediste:**
  - SEO como prioridad.
  - Web de confianza, sobria, útil y rápida.
  - Escalable, con zonas y profesionales fáciles de gestionar (normalmente por provincia).
  - Segura, migrable y sin complejidad.
  - Que yo haga el 100 % posible del trabajo.
- **Decisiones tuyas:**
  - El profesional puede aparecer con su nombre, pero el contacto siempre por nosotros.
  - Puedes aportar tus marcas comerciales para dar autoridad. No hay fotos de personas.
  - De momento solo SEO. Google Ads quizá más adelante si hay tiempo.

---

## 1. Arquitectura (lo más simple que resuelve todo)

**La web pública es HTML estático generado.** PHP solo se ejecuta en 3 sitios: el formulario de presupuesto, el panel de gestión y un registro de clics.

| Pieza | Decisión | Por qué |
|---|---|---|
| Páginas públicas | HTML estático generado en GitHub Actions con PHP 8.3 y plantillas Twig | Lo más rápido posible, casi imposible de hackear, se migra copiando carpetas |
| Contenido | Archivos Markdown en git, que escribo yo | Sin CMS que mantener ni atacar; todo versionado |
| Datos de municipios y agua | CSV en git (INE + SINAC) que se convierten en páginas al generar la web | Reproducible y con fecha y fuente de cada dato |
| Datos de operación | SQLite (un único archivo fuera de la carpeta pública), accedido con PDO | No hay que crear BD; migrar = copiar un archivo; se puede pasar a MySQL cambiando la configuración |
| Formulario | `/presupuesto/` en PHP, 2 pasos, funciona sin JavaScript | Muestra el texto legal correcto según tu código postal antes de enviar |
| Panel | `/gestion-xxxx/` en PHP, con usuario, contraseña y 2FA (TOTP) | Gestionas zonas, profesionales y leads sin tocar código |
| Medición | Search Console + leads por página de origen + clics en teléfono y WhatsApp guardados sin cookies | Sin Google Analytics ni scripts de terceros, así que no hace falta banner de cookies |
| Despliegue | GitHub Actions: tests → generar web → comprobaciones SEO → rsync por SSH a Raiola (FTPS si no hay SSH) | Nunca necesito tu contraseña del hosting |

### Carpetas en el servidor (el despliegue nunca toca los datos)

```
~/public_html/        ← web generada (HTML) + 3 puntos de entrada PHP + .htaccess
~/aguasincal-app/     ← código PHP, vendor y plantillas del panel (se reemplaza en cada despliegue)
~/aguasincal-data/    ← db.sqlite, config.local.php, logs, backups (NUNCA lo toca el despliegue)
```

### Repositorio (EdgarPGitHub/aguasincal)

```
config/        site.php (titular, teléfono, WhatsApp), servicios.php, zonas-publicadas.php
content/       servicios/, ciudades/, guias/, paginas/   (Markdown + front matter obligatorio)
data/          fuentes/ (crudos con fecha), municipios.csv, agua.csv
templates/     Twig (layout, componentes, páginas)
assets/        css, js (buscador, formulario, calculadora), img, fuentes del sistema
build/         build.php (genera dist/) + checks/ (comprobaciones SEO que bloquean el despliegue)
scripts/       import_ine.php, import_sinac.php, dataforseo_keywords.php, rank_tracking.php
app/           src/ (Leads, Cobertura, Mailer, Auth, Firma de enlaces, RateLimit), panel/
migrations/    SQL
tests/         PHPUnit
.github/workflows/  ci.yml, deploy.yml
```

Dependencias: solo librerías mantenidas vía Composer.
- `twig/twig` (escapado automático)
- `league/commonmark` + `symfony/yaml` (contenido)
- `phpmailer/phpmailer` (SMTP)
- Una librería de TOTP mantenida para el 2FA

Dependabot y `composer audit` en CI.

### Base de datos SQLite (6 tablas)

- **`profesionales`:** nombre comercial, razón social (para el texto legal), email, WhatsApp, precio por lead, activo.
- **`cobertura`:** provincia × servicio, modo `activo` o `espera`, profesional.
  - Si una combinación no está definida, es `espera`.
  - Arranque: 08, 17, 25 y 43 (las cuatro catalanas) y 29 (Málaga), en activo con tu profesional para todos los servicios menos comunidades.
- **`leads`:** contenido:
  - Servicio, modalidad (compra o alquiler) y tipo de cliente (particular, empresa, hostelería, comunidad).
  - CP y provincia. La provincia sale de los 2 primeros dígitos del CP.
  - Municipio, personas, mensaje, nombre, teléfono y email (opcional).
  - Modo en el momento del alta y profesional.
  - Estado: `nuevo`, `espera`, `enviado`, `válido`, `inválido` (con motivo).
  - Versión del texto de consentimiento, página de origen, referrer y UTM/gclid.
  - IP en hash y fechas.
- **`eventos`:** contador diario de clics por página y tipo (llamar, WhatsApp). Sin datos personales.
- **`admin`:** usuarios y TOTP, más `intentos_login`.

---

## 2. Flujo de leads y gestión de zonas

1. **Paso 1, en cualquier página (HTML estático):** servicio, CP y tipo de cliente. El botón lleva a `/presupuesto/`.
2. **Paso 2, `/presupuesto/` en PHP (noindex):** consulta la cobertura en la BD y muestra:
   - **Activo:** "Te atenderá [Empresa], profesional verificado de tu zona". El consentimiento nombra a la empresa a la que se cederán los datos.
   - **Espera:** "Aún no tenemos profesional verificado en tu zona. Si quieres, te avisamos cuando lo tengamos". El consentimiento es solo para que te contactemos nosotros.
3. **Al enviar:**
   - Validación y antispam: campo trampa, tiempo mínimo y límite por IP. Turnstile solo si aparece spam.
   - Se guarda el lead.
   - Te llega un email con el lead y enlaces de un clic: **Enviar a [Profesional] por email**, **Reenviar por WhatsApp** (texto ya rellenado) y **Marcar inválido**.
   - Los enlaces van firmados y caducan. Abren una página de confirmación con botón, para que los antivirus de correo no los activen solos.
   - Si el usuario dio email, recibe una confirmación automática.
4. **Teléfono y WhatsApp:**
   - Los botones apuntan a TU número y solo se muestran en páginas de zonas cubiertas, para no colapsarte con llamadas de otras zonas.
   - El WhatsApp lleva un texto prellenado con la página de origen ("Vengo de aguasincal.es – descalcificador en Sabadell").
   - En el panel puedes dar de alta un lead que entró por teléfono o WhatsApp.
5. **Leads en lista de espera:** nunca se ceden automáticamente. Cuando haya profesional en esa zona, se vuelve a contactar a la persona antes de pasar sus datos (lo exige el RGPD).

**Panel (3 pantallas):**

- **Leads:**
  - Filtros por provincia, servicio y estado.
  - Ficha con notas, envío y alta manual.
  - Exportar CSV mensual por profesional para facturar.
- **Cobertura:** cuadrícula provincias × servicios con modo y profesional. Al lado, el número de leads en espera de los últimos 90 días: es la señal de dónde buscar el siguiente profesional. Si tu profesional se satura, pasas una provincia a "espera" con un clic.
- **Profesionales:** alta, baja y datos.

**Abrir una zona nueva:**
- Asignar profesional es inmediato desde el panel.
- Publicar páginas locales de esa provincia requiere contenido, precios y fotos. Me lo pides, preparo las páginas y se despliega.
- Las provincias con páginas publicadas se gestionan en git (`config/zonas-publicadas.php`), no desde el panel.

---

## 3. Arquitectura SEO

**Patrones de URL** (cada patrón es inequívoco; los slugs definitivos salen del estudio de keywords):

| Tipo | URL | Fase |
|---|---|---|
| Hubs de servicio | `/descalcificadores/`, `/osmosis-inversa/`, `/filtros-agua/`, `/fuentes-agua-empresas/`, `/mantenimiento-reparacion/` | 1 |
| Servicio × ciudad (comerciales) | `/{servicio}/{ciudad}/` (p. ej. `/descalcificadores/sabadell/`) | 1 |
| Segmentos B2B | `/osmosis-hosteleria/` (venta y alquiler), sección de empresas dentro de fuentes | 1 |
| Guías | `/guias/{slug}/` | 1 y 2 |
| Datos de agua | `/dureza-agua/`, `/dureza-agua/{provincia}/`, `/dureza-agua/{provincia}/{municipio}/` | 1 |
| Herramientas | Calculadora integrada en las páginas de municipio + `/calculadora-descalcificador/` | 1 |
| Confianza | `/quienes-somos/`, `/como-funciona/`, `/metodologia-datos/`, legales | 1 |
| Comunidades (espera) | `/descalcificacion-comunidades/` | 2 |

**Reglas fijas:**
- Minúsculas, barra final y canonical sin parámetros.
- `/presupuesto/` en noindex.
- Nombres oficiales de municipio (catalán en Cataluña) con slug sin tildes.
- Sitemaps por tipo de página.
- Schema: Organization, BreadcrumbList, Article (con "Revisado por"), Service.
- Sin FAQPage: Google ya no muestra resultados enriquecidos de FAQ para sitios como este.

**Valor único por página (contra el "contenido escalado"):**

- **Página de municipio:**
  - Dureza oficial del SINAC, por zona de abastecimiento, con fecha y fuente.
  - Medidor visual de dureza.
  - Recomendación concreta (descalcificador, ósmosis o filtro) y tamaño de equipo.
  - Sal al año y coste de la cal.
  - Calculadora.
  - Enlace a la página comercial de la ciudad si existe y municipios cercanos.
- **Página comercial de ciudad:**
  - Datos del agua de esa ciudad.
  - Precios reales del profesional (compra y alquiler), quién atiende, plazos y garantía.
  - Fotos reales de instalaciones (sin datos GPS y con permiso del cliente).
  - Preguntas locales.
- **Guías:** salen de una entrevista con el técnico. Yo redacto, él aprueba y firma "Revisado por [nombre], técnico de [Empresa]". Nunca autoría inventada.
- **Solo se publican e indexan unas 60–120 páginas de municipio:** capitales y municipios de más de 10–15 mil habitantes con muestra reciente. El resto aparece en la tabla de cada provincia y en el buscador, sin página propia. Se amplía según lo que muestre Search Console.

**Comprobaciones automáticas en CI** (si fallan, no se despliega):
- Slugs únicos.
- Que el sitemap coincida con las páginas indexables.
- Title, meta description, H1 y canonical presentes.
- Enlaces internos rotos.
- JSON-LD válido.
- Similitud de texto entre páginas locales por debajo del umbral.
- Front matter completo: revisor, fecha de actualización, fuente de datos.

**Enlaces (autoridad):**
- Notas de datos para prensa local ("Los municipios con el agua más dura de Cataluña / Málaga").
- Enlaces desde las webs de tus marcas y del profesional.
- Recursos útiles enlazables (tabla de dureza descargable, calculadora).

---

## 4. Diseño

- **Estilo:** sobrio y de confianza. Fondo blanco, azul marino con un acento de color agua, tipografía del sistema (ninguna petición externa) y mucho aire.
- **Componentes:**
  - Buscador de municipio con autocompletado desde un JSON.
  - Medidor de dureza y tarjeta de recomendación.
  - Tabla de precios y formulario en 2 pasos.
  - Barra fija en móvil (Presupuesto / Llamar / WhatsApp; los dos últimos solo en zonas cubiertas).
  - Franja de confianza ("Datos oficiales SINAC · Profesional verificado · Gratis y sin compromiso") y logos de tus marcas y del profesional.
  - Sin reseñas inventadas.
- **Rendimiento:** menos de 100 KB por página entre HTML, CSS y JS, sin frameworks, con LCP por debajo de 1,5 s y accesibilidad AA.

## 5. Seguridad, privacidad y copias

- **Superficie mínima:** el público solo ve HTML, más el formulario y el panel. Sin CMS, sin plugins y sin subida de archivos.
- **Protección del código y de los datos:**
  - Código y datos fuera de `public_html`.
  - Twig con escapado automático y consultas preparadas con PDO.
  - Protección CSRF: en el panel con sesión; en el formulario público con un token firmado, para no usar cookies.
- **Panel:**
  - Ruta no obvia, contraseña argon2id y 2FA.
  - Bloqueo por intentos, cookies seguras y política CSP estricta.
  - noindex.
- **Cabeceras:** HSTS, CSP, frame-ancestors, nosniff, Referrer-Policy y Permissions-Policy. Se bloquea el acceso a archivos ocultos.
- **Email:** SPF, DKIM y DMARC activados antes del lanzamiento, y SMTP con una cuenta propia (avisos@).
- **RGPD:**
  - Aviso legal y política de privacidad con tus datos de titular.
  - Cesión al profesional nombrado y acuerdo de cesión de datos con él (te preparo un borrador para que lo revise un abogado).
  - Conservación: leads anonimizados a los 24 meses y los de espera a los 12, con una tarea programada.
- **Copias:** las de Raiola, más una copia diaria de la base de datos con rotación de 14 días en `~/aguasincal-data/backups`.
- **WordPress de prueba:** antes de borrarlo reviso URLs indexadas y enlaces (Search Console y Wayback Machine) y redirijo lo que valga. Luego copia de seguridad y fuera.

---

## 6. Fases (ordenadas por retorno)

### Fase 0: datos y decisiones (semana 1, en paralelo con el inicio del desarrollo)

1. **Prueba de datos SINAC con unos 20 municipios** (Barcelona, Sabadell, Girona, Lleida, Reus, Málaga, Marbella…). Qué parámetros hay, varias zonas por municipio, fechas de muestra. Las plantillas de datos se diseñan con el resultado. Si faltan datos de ciudades grandes, uso los que publica su empresa de aguas.
2. **Estudio de keywords con DataForSEO** (España, en castellano, catalán e inglés para la Costa del Sol):
   - Búsquedas por servicio × ciudad.
   - Alquiler (ósmosis, fuentes, hostelería).
   - Averías y precios.
   - Quién ocupa ahora los primeros resultados.

   Entregable: `docs/keyword-map.csv` con cada keyword asignada a su página y la prioridad.
3. **Inventario cerrado de páginas de la Fase 1** con sus URLs definitivas.
4. **Entrevista con el técnico** (te paso el guion) y **acuerdo con el profesional** (te paso un borrador): precio por lead, qué es un lead válido, plazo para reclamar inválidos, tiempo de respuesta y capacidad.

### Fase 1: MVP que genera leads en Cataluña y Málaga (semanas 2–4)

1. Plataforma: generador de la web, plantillas y diseño, formulario de 2 pasos, emails con enlaces de un clic, panel y despliegue automático.
2. **Primero lo que da dinero:** unas 15–20 páginas comerciales servicio × ciudad en zonas cubiertas, según el volumen de búsquedas:
   - Barcelona y área metropolitana.
   - Girona, Lleida, Tarragona y Reus.
   - Málaga, Marbella, Fuengirola, Torremolinos, Benalmádena, Estepona, Mijas y Vélez.
3. Los 5 hubs de servicio y la página de ósmosis para hostelería (venta y alquiler).
4. Unas 10 guías de compra o urgencia: precios de descalcificador, ósmosis y mantenimiento; alquiler o compra; las averías más buscadas; cada cuánto cambiar filtros.
5. Datos: `/dureza-agua/`, las 5 provincias (tabla completa) y entre 60 y 120 municipios, con la calculadora.
6. Páginas de confianza y legales, sitemaps, Search Console y Bing.
7. **Lanzamiento:** la web queda protegida con contraseña hasta que des el visto bueno; después se abre.

### Fase 2: crecer en lo que funciona (semanas 5–12)

- Más guías de averías y mantenimiento (por tipo de válvula y de equipo) a partir de la entrevista.
- Más páginas de ciudad donde Search Console muestre impresiones.
- Campaña de enlaces con los datos de dureza.
- Página de comunidades en lista de espera, para medir la demanda.
- Acceso a la API de Search Console y seguimiento semanal de posiciones con DataForSEO.
- Informe mensual: leads por página, provincia y servicio, y tasa de conversión por página.
- Si queda tiempo: prueba pequeña de Google Ads en ciudades cubiertas, medida en el servidor con UTM/gclid (sin poner etiquetas de Google en la web).

### Fase 3: siguiente zona y idiomas (meses 3–6)

- **Una sola zona nueva**, la que más leads acumule en espera (previsiblemente Comunidad Valenciana, Murcia o Baleares):
  - Lista privada de empresas candidatas con DataForSEO para ofrecerles leads.
  - Página `/profesionales/` para captar colaboradores.
  - Publicar sus páginas locales cuando haya profesional.
- **Idioma:** inglés para la Costa del Sol o catalán, según lo que digan las keywords.
- Buscar un profesional nacional de fuentes para empresas, para no perder ese B2B fuera de las zonas cubiertas.

### Fase 4: extras (6 meses en adelante)

- Directorio de empresas verificadas en el que cada empresa reclama su ficha (nunca un directorio extraído de Google).
- Afiliación con equipos domésticos.
- B2B adyacente: legionela, más hostelería.
- Reutilizar el sistema en otro sector.

---

## 7. Lo que te iré pidiendo, paso a paso (nunca contraseñas por el chat)

**Paso 1, al aprobar el plan (no me bloquea, empiezo a programar ya):**
- Tus datos de titular para el aviso legal (nombre o razón social, NIF, domicilio, email).
- El teléfono y WhatsApp de la web (recomiendo un número dedicado con WhatsApp Business).
- El email donde recibir los leads.
- **Del profesional:**
  - Nombre comercial y razón social, y su email y WhatsApp para enviarle leads.
  - Marcas que instala y repara, garantía, plazo de respuesta y capacidad semanal.
  - Nombre del técnico revisor (sin foto).
- **Precios orientativos reales:**
  - Descalcificador instalado.
  - Ósmosis en venta y alquiler, doméstica y para hostelería.
  - Fuentes en venta y alquiler mensual.
  - Mantenimiento, filtros y avería con desplazamiento.
- Fotos de instalaciones y equipos (sin personas está bien).
- Tus marcas: logos, qué son y su web.

**Paso 2, ajustes del entorno de Claude (para datos y keywords):**
- **Dominios permitidos:**
  - `api.dataforseo.com`
  - `sinac.sanidad.gob.es`, `www.sanidad.gob.es`
  - `www.ine.es`, `servicios.ine.es`
  - `centrodedescargas.cnig.es`
  - `web.archive.org`
  - `aguasincal.es`, `www.aguasincal.es`
- **Variables de entorno:** `DATAFORSEO_LOGIN` y `DATAFORSEO_PASSWORD`. Presupuesto aproximado para la Fase 0: 20–30 $. Se aplican en una sesión nueva.

**Paso 3, cPanel de Raiola (antes del primer despliegue):**
- Confirmar si aguasincal.es es el dominio principal de la cuenta.
- PHP 8.3 con pdo_sqlite, mbstring, intl y opcache.
- Activar SSH y autorizar una clave (te doy los pasos; la clave privada va a GitHub, no al chat).
- Crear el buzón avisos@ y activar SPF, DKIM y DMARC.
- SSL activo.
- 2 tareas programadas (copia y purga): te paso las líneas.

**Paso 4, GitHub:** guardar como secretos SSH_HOST, SSH_PORT, SSH_USER, SSH_KEY, SMTP_PASS y APP_KEY (te digo cómo generar cada uno). Fusionar el PR a `main` = publicar.

**Paso 5, lanzamiento:**
- Verificar Search Console por DNS (registro TXT en Raiola) y darme acceso de lectura mediante una cuenta de servicio (te guío).
- Importar el sitio en Bing Webmaster Tools.

---

## 8. Revisión crítica: qué cambié y qué riesgos quedan

**Simplificaciones respecto a la primera idea:**

1. **Web estática + PHP mínimo** en vez de PHP renderizando cada página: más rápida, más segura, fácil de migrar y probada antes de cada despliegue.
2. **SQLite en vez de MySQL:** no hay base de datos que crear ni credenciales, y migrar es copiar un archivo. Se puede cambiar a MySQL cuando haga falta.
3. **Unas 100 páginas de municipio indexadas**, no 1.050, para evitar el patrón de "contenido escalado".
4. **Primero páginas comerciales de ciudades cubiertas**; lo nacional, los idiomas y el directorio, después.
5. **Solo 2 modos de cobertura** (activo / espera) y 5 estados de lead.
6. **Reenvío con un clic desde el email**, sin entrar al panel.
7. **Nada de** subdominio de pruebas, Analytics, banner de cookies, directorio extraído de Google en el MVP, ni página de captación de profesionales hasta la Fase 3.
8. **Consentimiento mostrado después de saber el CP**, así el texto legal siempre es el correcto.

**Alternativas descartadas:**
- **WordPress:** plugins, ataques, lentitud y SEO programático difícil.
- **Laravel:** sobredimensionado para esto.
- **Astro u otro generador en JavaScript:** obliga a mantener dos lenguajes; PHP es nativo del hosting.

**Riesgos que quedan:**
- **Calidad de los datos del SINAC:** la Fase 0 lo prueba antes de diseñar. Plan B: datos de las empresas de aguas.
- **Capacidad del único profesional:** se controla con el modo "espera" por provincia.
- **Dominio sin autoridad:** de 6 a 12 meses de SEO, más la campaña de enlaces.
- **Que el hosting bloquee SSH desde GitHub:** se prueba el primer día. Plan B: FTPS.
- **Que los emails acaben en spam:** SPF, DKIM y DMARC más una prueba completa antes del lanzamiento.

---

## 9. Verificación

- **Tests (PHPUnit):**
  - CP → provincia → modo → texto legal.
  - Creación del lead y estados.
  - Enlaces firmados (caducidad y confirmación).
  - Antispam y límite por IP.
  - Anonimización.
  - Escapado en el panel.
- **Generación y comprobaciones SEO en CI**, como en la sección 3.
- **Revisión visual:** capturas con Playwright en móvil y escritorio de cada plantilla (las adjunto en el PR) y Lighthouse por encima de 95 en rendimiento, SEO y accesibilidad.
- **Primer despliegue con la web protegida:**
  - Comprobaciones automáticas: páginas responden 200; las carpetas de código y datos no son accesibles; cabeceras presentes.
  - Prueba de principio a fin:
    - Lead en Sabadell (activo): email recibido, "enviar al profesional", email del profesional y estado actualizado.
    - Lead en Valencia (46xxx): texto de lista de espera, sin cesión.
  - Clic en WhatsApp registrado.
- **Tras el lanzamiento:** sitemaps procesados en Search Console, cobertura de indexación y seguimiento semanal de posiciones.

## 10. Al aprobar, empiezo por

Trabajo en la rama `claude/bold-turing-l5nm4x`; `main` es producción.

1. Esqueleto del repositorio y CI.
2. Diseño y plantillas.
3. Formulario, leads y panel, con sus tests.
4. Generador y comprobaciones SEO.
5. Contenido base con datos provisionales.

A la vez te pido el Paso 1 y el Paso 2. Lo que dependa de datos reales (SINAC, keywords, precios) se completa en cuanto llegue.
