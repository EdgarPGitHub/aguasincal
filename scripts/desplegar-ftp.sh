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

TRABAJO=$(mktemp -d)

# Sube una carpeta comparando por contenido: en aguasincal-data/subida-<nombre>.sha1 queda la huella de cada archivo
# subido, y la siguiente vez solo se suben los nuevos o cambiados y se borran los que ya no existen (lo demás del
# servidor no se toca). Sin esa lista, con SUBIDA_COMPLETA=si o si cambian muchos archivos, se sube todo con mirror.
subir() {
  local origen=$1 destino=$2 nombre=$3; shift 3
  local nuevo="$TRABAJO/nuevo-$nombre" viejo="$TRABAJO/viejo-$nombre" lista="aguasincal-data/subida-$nombre.sha1"
  (cd "$origen" && find . -type f -print0 | sort -z | xargs -0 sha1sum) > "$nuevo"
  local completa=si
  if [ "${SUBIDA_COMPLETA:-no}" != "si" ] \
     && timeout 90 lftp -c "$AJUSTES; $CONECTAR; get \"$lista\" -o \"$viejo\"" < /dev/null > /dev/null 2>&1 && [ -s "$viejo" ]; then
    completa=no
  fi
  if [ "$completa" = "no" ]; then
    comm -13 <(sort "$viejo") <(sort "$nuevo") | cut -c43- > "$TRABAJO/cambiados-$nombre"
    comm -23 <(cut -c43- "$viejo" | sort) <(cut -c43- "$nuevo" | sort) > "$TRABAJO/borrados-$nombre"
    local cambiados borrados
    cambiados=$(grep -c . "$TRABAJO/cambiados-$nombre" || true)
    borrados=$(grep -c . "$TRABAJO/borrados-$nombre" || true)
    if [ "$cambiados" -gt 400 ]; then
      echo "  $nombre: $cambiados archivos cambiados; se sube todo"
      completa=si
    else
      echo "  $nombre: $cambiados archivos nuevos o cambiados, $borrados borrados"
      {
        echo "$AJUSTES; $CONECTAR"
        sed 's#^\./##' "$TRABAJO/cambiados-$nombre" | xargs -r -n1 dirname | sort -u | { grep -v '^\.$' || true; } \
          | while read -r d; do echo "mkdir -p -f \"$destino/$d\""; done
        sed 's#^\./##' "$TRABAJO/cambiados-$nombre" | while read -r f; do echo "put \"$origen/$f\" -o \"$destino/$f\""; done
        sed 's#^\./##' "$TRABAJO/borrados-$nombre" | while read -r f; do echo "rm -f \"$destino/$f\""; done
      } > "$TRABAJO/ordenes-$nombre"
      timeout 1200 lftp -f "$TRABAJO/ordenes-$nombre" < /dev/null
    fi
  fi
  if [ "$completa" = "si" ]; then
    echo "  $nombre: subida completa"
    timeout 1200 lftp -c "$AJUSTES; $CONECTAR; mkdir -p -f \"$destino\";
      mirror -R --delete --parallel=6 --no-perms --verbose=1 $* \"$origen/\" \"$destino/\"" < /dev/null
  fi
  # La lista se guarda solo si todo lo anterior ha ido bien (set -e)
  timeout 90 lftp -c "$AJUSTES; $CONECTAR; put \"$nuevo\" -o \"$lista\"" < /dev/null
}

echo "→ Subiendo aplicación, configuración y web"
timeout 90 lftp -c "$AJUSTES; $CONECTAR;
mkdir -p -f aguasincal-app; mkdir -p -f aguasincal-data; mkdir -p -f \"$CARPETA_PUBLICA\";
chmod 700 aguasincal-data || echo 'aviso: el servidor no permite chmod en aguasincal-data'" < /dev/null
subir "$PAQUETE" aguasincal-app app
timeout 90 lftp -c "$AJUSTES; $CONECTAR; put \"$CONFIG\" -o aguasincal-data/config.local.php;
chmod 600 aguasincal-data/config.local.php || echo 'aviso: el servidor no permite chmod en config.local.php'" < /dev/null
subir "$DIST" "$CARPETA_PUBLICA" web \
  --exclude-glob .well-known/ --exclude-glob cgi-bin/ --exclude-glob .user.ini --exclude-glob php.ini --exclude-glob error_log
rm -rf "$TRABAJO"
echo "✓ Subida completada"
