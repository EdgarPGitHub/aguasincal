#!/usr/bin/env bash
# Sube la web al hosting por FTP cifrado (FTPS). Lo usa .github/workflows/deploy.yml.
#
#   scripts/desplegar-ftp.sh <dist> <paquete-app> <config.local.php>
#
# Variables de entorno:
#   FTP_HOST, FTP_USUARIO, LFTP_PASSWORD (contraseña FTP), CARPETA_PUBLICA (raíz del dominio, p. ej. aguasincal.es)
#   FTP_PORT (21), FTP_VERIFICAR_CERT (si|no, por defecto si)
#
# En el servidor (rutas relativas a la carpeta inicial de la cuenta FTP, que debe ser la carpeta personal):
#   $CARPETA_PUBLICA/   ← web (mirror --delete; respeta .well-known, cgi-bin, .user.ini, php.ini, error_log)
#   aguasincal-app/     ← código PHP + vendor + plantillas (mirror --delete)
#   aguasincal-data/    ← config.local.php (600); la base de datos y las copias las crea la propia web
set -euo pipefail

DIST=${1:?falta la carpeta dist}
PAQUETE=${2:?falta la carpeta del paquete}
CONFIG=${3:?falta config.local.php}
: "${FTP_HOST:?falta FTP_HOST}" "${FTP_USUARIO:?falta FTP_USUARIO}" "${LFTP_PASSWORD:?falta la contraseña FTP}" "${CARPETA_PUBLICA:?falta CARPETA_PUBLICA}"
PUERTO=${FTP_PORT:-21}
VERIFICAR=yes
[ "${FTP_VERIFICAR_CERT:-si}" = "no" ] && VERIFICAR=no

AJUSTES="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate $VERIFICAR; \
set ftp:passive-mode true; set net:timeout 30; set net:max-retries 2; set net:reconnect-interval-base 5; \
set cmd:fail-exit true; set xfer:clobber true"
CONECTAR="open --env-password -u \"$FTP_USUARIO\" -p $PUERTO \"$FTP_HOST\""

echo "→ Comprobando el acceso FTP"
if ! timeout 90 lftp -c "$AJUSTES; $CONECTAR; ls" < /dev/null > /dev/null; then
  echo "✗ No se puede entrar por FTP a $FTP_HOST con el usuario $FTP_USUARIO." >&2
  echo "  Revisa los secretos FTP_HOST, FTP_USUARIO y FTP_CLAVE (y FTP_VERIFICAR_CERT si el error habla del certificado)." >&2
  exit 1
fi

echo "→ Conservando el bloque de PHP que cPanel pone en .htaccess (si existe)"
if timeout 90 lftp -c "$AJUSTES; $CONECTAR; cat \"$CARPETA_PUBLICA/.htaccess\"" < /dev/null > /tmp/htaccess-remoto 2>/dev/null \
   && grep -q 'php -- BEGIN cPanel-generated handler' /tmp/htaccess-remoto; then
  printf '\n' >> "$DIST/.htaccess"
  sed -n '/php -- BEGIN cPanel-generated handler/,/php -- END cPanel-generated handler/p' /tmp/htaccess-remoto >> "$DIST/.htaccess"
  echo "  bloque conservado"
fi

echo "→ Subiendo aplicación, configuración y web"
timeout 1200 lftp -c "$AJUSTES; $CONECTAR;
mkdir -p -f aguasincal-app; mkdir -p -f aguasincal-data; mkdir -p -f \"$CARPETA_PUBLICA\";
chmod 700 aguasincal-data || echo 'aviso: el servidor no permite chmod en aguasincal-data';
mirror -R --delete --parallel=6 --no-perms --verbose=1 \"$PAQUETE/\" aguasincal-app/;
put \"$CONFIG\" -o aguasincal-data/config.local.php;
chmod 600 aguasincal-data/config.local.php || echo 'aviso: el servidor no permite chmod en config.local.php';
mirror -R --delete --parallel=6 --no-perms --verbose=1 \
  --exclude-glob .well-known/ --exclude-glob cgi-bin/ --exclude-glob .user.ini --exclude-glob php.ini --exclude-glob error_log \
  \"$DIST/\" \"$CARPETA_PUBLICA/\"" < /dev/null
echo "✓ Subida completada"
