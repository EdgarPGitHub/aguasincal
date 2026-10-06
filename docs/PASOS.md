# Lo que necesito de ti, paso a paso

Nunca me pases contraseñas, claves ni tokens por el chat. Cada paso indica dónde se guardan.

---

## Paso 1 · Datos del negocio (ahora)

Estos datos no bloquean el desarrollo; sin ellos la web no se puede lanzar. Puedes enviármelos por el chat.

### Tus datos (aviso legal y privacidad)

- [x] Titular, NIF, domicilio y email público (info@aguasincal.es): ya están en `config/site.php`.
- [ ] Teléfono público y WhatsApp de la web: cuando los tengas, se ponen como **variables** (ver Paso 4); no hace falta tocar código. Recomiendo un número solo para la web, con WhatsApp Business.

### Del profesional colaborador

- [ ] Nombre comercial (se muestra al cliente) y razón social (se usa en el texto legal de cesión de datos)
- [ ] Email y WhatsApp donde quiere recibir los leads
- [ ] Marcas que instala y repara, garantía que da, plazo de respuesta y cuántos leads por semana puede atender
- [ ] Nombre y cargo del técnico que revisará las guías (no hace falta foto)
- [ ] ¿Cubre de verdad toda la provincia de Lleida y la de Girona, incluidas las zonas rurales?

### Precios orientativos reales (rangos con IVA)

- [ ] Descalcificador instalado (vivienda pequeña / grande; empresa)
- [ ] Ósmosis: venta y alquiler mensual, doméstica y para hostelería
- [ ] Fuentes y dispensadores: venta y alquiler mensual
- [ ] Mantenimiento anual, cambio de filtros, desplazamiento y avería

### Material

- [ ] Fotos reales de instalaciones y equipos (sin personas está bien). Con permiso del cliente y sin datos de ubicación; las fotos las limpio yo antes de publicarlas.
- [ ] Logos de tus marcas comerciales, qué son y su web

### Acuerdo con el profesional

- [ ] Precio por lead o comisión
- [ ] Qué cuenta como lead válido y plazo para reclamar los inválidos
- [ ] Tiempo máximo de respuesta

Hay un borrador en [acuerdo-profesional.md](acuerdo-profesional.md).

### Entrevista con el técnico

- [ ] 30–45 minutos usando el guion de [entrevista-tecnico.md](entrevista-tecnico.md). Me pasas un audio transcrito o notas y redacto las guías a partir de ahí.

---

## Paso 2 · Ajustes del entorno de Claude (para datos y keywords)

En la sesión de Claude: menú del entorno (barra de título) → **Edit**.

1. **Network access → Custom**, manteniendo la lista por defecto, y añade estos dominios:
   ```
   api.dataforseo.com
   sinac.sanidad.gob.es
   www.sanidad.gob.es
   www.ine.es
   servicios.ine.es
   centrodedescargas.cnig.es
   web.archive.org
   aguasincal.es
   www.aguasincal.es
   ```
2. **Variables de entorno:**
   ```
   DATAFORSEO_LOGIN=<tu login de la API>
   DATAFORSEO_PASSWORD=<tu password de la API>
   ```
   Las encontrarás en el panel de DataForSEO → API Access. Es la contraseña de la API, no la de tu cuenta.

Presupuesto aproximado para la Fase 0: 20–30 $.

Los cambios se aplican al abrir una sesión nueva.

---

## Paso 3 · cPanel de Raiola (antes del primer despliegue)

1. **¿aguasincal.es es el dominio principal de la cuenta?** Dímelo. Si tienes otros dominios dentro de `public_html`, dime sus carpetas: el despliegue sincroniza `public_html` y hay que excluirlas.
2. **WordPress de prueba:**
   - Haz una copia completa: **Copias de seguridad** → descargar archivos y base de datos.
   - El primer despliegue sustituye el contenido de `public_html`.
   - Después borra su base de datos desde **Bases de datos MySQL**.
3. **Selector de PHP:** PHP **8.3** con `pdo_sqlite`, `sqlite3`, `mbstring`, `intl` y `opcache` activadas.
4. **SSH:**
   - **Acceso SSH → Administrar claves SSH → Generar una nueva clave**, sin frase de contraseña.
   - **Autorízala** (Manage → Authorize).
   - Copia la **clave privada** (botón View/Download): va a GitHub (Paso 4), no al chat.
   - Apunta el servidor SSH, tu usuario de cPanel y el puerto SSH. Los ves en la portada de cPanel o en el email de alta de Raiola.
5. **Correo:**
   - Crea el buzón `info@aguasincal.es`. Es el email público y el que envía los avisos de leads.
   - En **Email Deliverability** deja **SPF y DKIM** en verde.
   - Añade un registro **DMARC** en el editor de zona DNS. Te paso el valor exacto.
6. **SSL:** comprueba en **SSL/TLS Status** que aguasincal.es y www tienen certificado (AutoSSL).
7. **Tarea programada (Cron Jobs)**, una vez al día de madrugada:
   ```
   php ~/aguasincal-app/scripts/cron-diario.php >> ~/aguasincal-data/logs/cron.log 2>&1
   ```

---

## Paso 4 · GitHub (despliegue automático)

Repositorio → **Settings → Secrets and variables → Actions**.

### Secrets (pestaña Secrets → New repository secret)

| Nombre | Qué poner |
|---|---|
| `SSH_HOST` | Servidor SSH de Raiola |
| `SSH_USER` | Usuario de cPanel |
| `SSH_PORT` | Puerto SSH (solo si no es 22) |
| `SSH_KEY` | Contenido completo de la clave privada generada en cPanel |
| `SMTP_PASS` | Contraseña del buzón `info@aguasincal.es` |
| `PANEL_RUTA` | Carpeta del panel, difícil de adivinar: minúsculas, números y guiones (p. ej. `gestion-7k2m9x`) |
| `ADMIN_EMAIL` | Tu email para entrar al panel |
| `ADMIN_CLAVE` | Contraseña inicial del panel (mínimo 12 caracteres) |
| `LEADS_EMAIL` | *(opcional)* Dónde recibir los leads; por defecto `info@aguasincal.es` |

La clave interna de la aplicación se genera sola en el servidor en el primer despliegue.

### Variables (pestaña Variables → New repository variable)

| Nombre | Valor | Para qué |
|---|---|---|
| `DESPLIEGUE_ACTIVO` | `si` | Activa el despliegue. Créala cuando los secretos estén puestos |
| `INDEXAR` | `no` (por defecto) | `no` = web visible pero fuera de Google; `si` = lanzamiento |
| `TELEFONO` | p. ej. `600 12 34 56` | Teléfono de la web; vacía = sin botón «Llamar» |
| `WHATSAPP` | p. ej. `600 12 34 56` | WhatsApp de la web; vacía = sin botón de WhatsApp |
| `PHP_BIN` | `php` | Solo si en SSH `php -v` no es la 8.3 (te lo digo tras el primer despliegue) |
| `RSYNC_EXCLUIR` | – | Carpetas de `public_html` que el despliegue no debe tocar |

**Publicar o actualizar:** Actions → **Despliegue** → **Run workflow** (o fusionar cambios en `main`). Tarda unos 2 minutos. Si un test o una comprobación falla, no se sube nada.

**Cambiar el teléfono o el WhatsApp:** edita la variable `TELEFONO` o `WHATSAPP` y pulsa **Run workflow**. Los botones de llamar y WhatsApp solo aparecen en las páginas de zonas con profesional, para no recibir llamadas de donde no trabajamos.

**Primer acceso al panel:** entra en `https://aguasincal.es/<PANEL_RUTA>/` con `ADMIN_EMAIL` y `ADMIN_CLAVE`. Te pedirá configurar la verificación en dos pasos con Google Authenticator o similar. Después:

- En **Contraseña**, cambia la contraseña inicial.
- En **Profesionales**, da de alta a tu profesional.
- En **Cobertura**, activa las provincias 08, 17, 25, 43 y 29 para todos sus servicios.

---

## Paso 5 · Lanzamiento

1. Revisas la vista previa en aguasincal.es y das el visto bueno.
2. Cambias la variable `INDEXAR` a `si` y relanzas el despliegue (Actions → Despliegue → Run workflow).
3. **Search Console:**
   - Añade la propiedad de dominio `aguasincal.es` y verifícala con el registro TXT en el editor de zona DNS de Raiola.
   - Envía `https://aguasincal.es/sitemap.xml`.
4. **Acceso de lectura para mí (recomendado), para analizar consultas y páginas:**
   - Crea en Google Cloud una cuenta de servicio con la API de Search Console activada.
   - Añade su email como usuario de la propiedad.
   - Guarda su clave JSON como variable de entorno `GSC_SA_JSON` en los ajustes del entorno de Claude.

   Te guío cuando lleguemos.
5. **Bing Webmaster Tools:** importa el sitio desde Search Console (2 minutos).
