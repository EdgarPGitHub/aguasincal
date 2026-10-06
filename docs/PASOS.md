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

## Paso 3 · cPanel de Raiola (5 minutos)

1. **Carpeta del dominio.** En **Dominios**, mira la columna «Raíz del documento» de aguasincal.es. Normalmente es `/home/TUUSUARIO/aguasincal.es`; apunta lo que va después de `/home/TUUSUARIO/` (por ejemplo `aguasincal.es` o `public_html/aguasincal.es`).
2. **PHP.** En **Seleccionar versión de PHP** (o MultiPHP), con PHP 8.5 en aguasincal.es, comprueba que están activadas las extensiones `pdo_sqlite`, `sqlite3` y `mbstring`.
3. **Cuenta FTP para publicar.** En **Cuentas FTP → Agregar cuenta FTP**:
   - Inicio de sesión: `despliegue` (quedará `despliegue@aguasincal.es`).
   - Contraseña: usa el generador y guárdala; va a GitHub.
   - **Directorio: bórralo y escribe `/`.** Necesita la carpeta personal para crear `aguasincal-app` y `aguasincal-data` fuera de la parte pública.
   - Cuota: ilimitada.
   - Después, en esa misma pantalla, «Configurar cliente FTP» te muestra el **servidor FTP**. Usa el nombre del servidor, porque su certificado es válido.
4. **Correo (ya tienes info@).** En **Email Deliverability**, deja SPF y DKIM en verde (botón «Reparar» si sale en rojo).
5. **Menos spam en info@:**
   - **Dirección predeterminada** (Default Address) → «Descartar con un error al remitente». Si ahora recoge todo lo que llega a direcciones que no existen, aquí entra mucho spam.
   - **Filtros de spam** (Spam Filters):
     - activa «Procesar correo nuevo y marcar como spam»;
     - activa «Mover el spam a una carpeta independiente»;
     - activa «Eliminar automáticamente» con puntuación **8**. Cuando veas que no se pierde nada bueno, baja a 6.
   - Para los leads usa un email que no esté publicado (secreto `LEADS_EMAIL`, por ejemplo tu Gmail), para que no se mezclen con el spam.
   - La web ya no deja info@ a la vista de los robots: se muestra como «info [arroba] aguasincal.es» y se convierte en enlace solo para las personas.

No hace falta SSH ni tarea cron: la web hace sola su copia de seguridad diaria y la limpieza de datos antiguos.

---

## Paso 4 · GitHub (10 minutos)

### 1. Secretos

Abre **https://github.com/EdgarPGitHub/aguasincal/settings/secrets/actions** → botón **New repository secret**. Crea uno por fila (Name = nombre exacto, Secret = valor):

| Name | Secret |
|---|---|
| `FTP_HOST` | Servidor FTP del Paso 3.3 |
| `FTP_USUARIO` | `despliegue@aguasincal.es` |
| `FTP_CLAVE` | Contraseña de esa cuenta FTP |
| `SMTP_PASS` | Contraseña del buzón `info@aguasincal.es` |
| `LEADS_EMAIL` | Email donde quieres recibir los leads (mejor uno no publicado) |
| `ADMIN_EMAIL` | Tu email para entrar al panel |
| `ADMIN_CLAVE` | Contraseña del panel (mínimo 12 caracteres) |
| `PANEL_RUTA` | Nombre secreto de la carpeta del panel: minúsculas, números y guiones (p. ej. `gestion-7k2m9x`) |

### 2. Variables

En la misma página, pestaña **Variables** → **New repository variable**:

| Name | Value |
|---|---|
| `CARPETA_PUBLICA` | Lo que apuntaste en el Paso 3.1 (p. ej. `aguasincal.es`) |
| `DESPLIEGUE_ACTIVO` | `si` |

### 3. Rama principal

**https://github.com/EdgarPGitHub/aguasincal/settings** → «Default branch» → icono de flechas → elige `main` → Update.

### 4. Publicar

**https://github.com/EdgarPGitHub/aguasincal/actions/workflows/deploy.yml** → **Run workflow** → rama `main` → **Run workflow**.

En unos 3 minutos aparece un ✓ verde y la web está en https://aguasincal.es (visible, pero fuera de Google). Si sale una ✗ roja, ábrela: el mensaje dice qué falta. Si no te queda claro, cópiamelo.

### 5. Primer acceso al panel

1. Entra en `https://aguasincal.es/<PANEL_RUTA>/` con `ADMIN_EMAIL` y `ADMIN_CLAVE`.
2. La primera vez te pide la verificación en dos pasos: escanea el QR con Google Authenticator (o similar) y escribe el código.
3. En **Profesionales**, da de alta a tu profesional.
4. En **Cobertura**, activa las provincias 08, 17, 25, 43 y 29 para sus servicios.

### Más adelante

- **Teléfono / WhatsApp:** crea las variables `TELEFONO` y `WHATSAPP` (p. ej. `600 12 34 56`) y vuelve a pulsar **Run workflow**. Los botones solo aparecen en las páginas de zonas con profesional.
- **Lanzamiento:** variable `INDEXAR` = `si` y **Run workflow**.
- Si GitHub dice que el certificado del servidor FTP no es válido, revisa que `FTP_HOST` sea el nombre del servidor que muestra «Configurar cliente FTP».

---

## Paso 5 · Lanzamiento

1. Revisas la vista previa en aguasincal.es y das el visto bueno.
2. Cambias la variable `INDEXAR` a `si` y vuelves a publicar (Actions → Despliegue → Run workflow).
3. **Search Console:**
   - Añade la propiedad de dominio `aguasincal.es` y verifícala con el registro TXT en el editor de zona DNS de Raiola.
   - Envía `https://aguasincal.es/sitemap.xml`.
4. **Acceso de lectura para mí (recomendado), para analizar consultas y páginas:**
   - Crea en Google Cloud una cuenta de servicio con la API de Search Console activada.
   - Añade su email como usuario de la propiedad.
   - Guarda su clave JSON como variable de entorno `GSC_SA_JSON` en los ajustes del entorno de Claude.

   Te guío cuando lleguemos.
5. **Bing Webmaster Tools:** importa el sitio desde Search Console (2 minutos).
