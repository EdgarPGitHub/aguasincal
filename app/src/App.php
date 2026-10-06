<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Twig\Environment;

/**
 * Contenedor mínimo de la aplicación: rutas, configuración y servicios perezosos.
 *
 * - $raiz: carpeta del código (repo en local, ~/aguasincal-app en el servidor).
 * - $datos: carpeta de datos persistentes (~/aguasincal-data en el servidor, var/ en local).
 */
final class App
{
    private array $configs = [];
    private ?PDO $pdo = null;
    private ?Environment $twig = null;
    private ?Provincias $provincias = null;

    public function __construct(
        public readonly string $raiz,
        public readonly string $datos,
        private readonly array $local,
    ) {
    }

    /** Arranque estándar: detecta la carpeta de datos y carga config.local.php. */
    public static function desdeEntorno(?string $raiz = null): self
    {
        $raiz ??= dirname(__DIR__, 2);
        $datos = getenv('AGUASINCAL_DATA') ?: '';
        if ($datos === '') {
            $hermana = dirname($raiz) . '/aguasincal-data';
            $datos = is_dir($hermana) ? $hermana : $raiz . '/var';
        }
        $local = [];
        if (is_file($datos . '/config.local.php')) {
            $local = require $datos . '/config.local.php';
        }
        return new self($raiz, $datos, is_array($local) ? $local : []);
    }

    /**
     * Lee config/{nombre}.php del repositorio (cacheado).
     * En "site", el teléfono y el WhatsApp pueden venir de la configuración local (bloque "contacto"),
     * que el despliegue rellena con las variables TELEFONO y WHATSAPP de GitHub.
     */
    public function config(string $nombre): array
    {
        if (!isset($this->configs[$nombre])) {
            $config = require $this->raiz . '/config/' . $nombre . '.php';
            if ($nombre === 'site') {
                $config = self::aplicarContacto($config, (array) $this->local('contacto', []));
            }
            $this->configs[$nombre] = $config;
        }
        return $this->configs[$nombre];
    }

    /** Sustituye teléfono y WhatsApp de site.php si vienen en $contacto (vacío = sin cambios). */
    public static function aplicarContacto(array $site, array $contacto): array
    {
        $telefono = trim((string) ($contacto['telefono'] ?? ''));
        if ($telefono !== '' && ($enlace = self::numeroInternacional($telefono)) !== null) {
            $site['telefono_visible'] = $telefono;
            $site['telefono_enlace'] = $enlace;
        }
        $whatsapp = trim((string) ($contacto['whatsapp'] ?? ''));
        if ($whatsapp !== '' && ($numero = self::numeroInternacional($whatsapp)) !== null) {
            $site['whatsapp'] = $numero;
        }
        return $site;
    }

    /** "600 12 34 56" → "34600123456"; "+44 7911 123456" → "447911123456"; null si no es válido. */
    public static function numeroInternacional(string $numero): ?string
    {
        $limpio = preg_replace('/[\s.\-()\/]+/', '', $numero);
        if (preg_match('/^(?:\+34|0034|34)?([6789]\d{8})$/', $limpio, $m)) {
            return '34' . $m[1];
        }
        if (preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $limpio, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Valor de config.local.php con notación "a.b". */
    public function local(string $clave, mixed $defecto = null): mixed
    {
        $valor = $this->local;
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $defecto;
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }

    public function esProduccion(): bool
    {
        return $this->local('entorno', 'desarrollo') === 'produccion';
    }

    public function clave(): string
    {
        $clave = (string) $this->local('app_key', '');
        if ($clave === '' && is_file($this->datos . '/app.key')) {
            // Clave generada en el servidor por el primer despliegue (no sale nunca del servidor).
            $clave = trim((string) file_get_contents($this->datos . '/app.key'));
        }
        if (strlen($clave) < 32) {
            if ($this->esProduccion()) {
                throw new \RuntimeException('Falta la clave de la aplicación (app.key o app_key en config.local.php, mínimo 32 caracteres)');
            }
            $clave = 'clave-de-desarrollo-no-usar-en-produccion';
        }
        return $clave;
    }

    public function db(): PDO
    {
        if ($this->pdo === null) {
            $dsn = (string) $this->local('db.dsn', '');
            if ($dsn === '') {
                if (!is_dir($this->datos)) {
                    mkdir($this->datos, 0750, true);
                }
                $dsn = 'sqlite:' . $this->datos . '/aguasincal.sqlite';
            }
            $this->pdo = Db::conectar($dsn);
            Db::migrar($this->pdo, $this->raiz . '/migrations');
        }
        return $this->pdo;
    }

    public function provincias(): Provincias
    {
        return $this->provincias ??= new Provincias($this->config('provincias'));
    }

    public function servicios(): array
    {
        return $this->config('servicios');
    }

    public function twig(): Environment
    {
        return $this->twig ??= Vista::crear($this, $this->esProduccion() ? $this->datos . '/cache/twig' : null);
    }

    public function cobertura(): Cobertura
    {
        return new Cobertura($this->db(), $this->servicios());
    }

    public function leads(): Leads
    {
        return new Leads($this->db(), $this->provincias(), $this->servicios());
    }

    public function profesionales(): Profesionales
    {
        return new Profesionales($this->db());
    }

    public function limitador(): Limitador
    {
        return new Limitador($this->db());
    }

    public function firma(): Firma
    {
        return new Firma($this->clave());
    }

    public function tokenFormulario(): TokenFormulario
    {
        return new TokenFormulario($this->clave());
    }

    public function correo(): Correo
    {
        return new Correo((array) $this->local('correo', ['transporte' => 'archivo']), $this->datos . '/correo');
    }

    public function notificaciones(): Notificaciones
    {
        return new Notificaciones($this, $this->correo());
    }

    public function auth(): Auth
    {
        $tfa = new TwoFactorAuth(new BaconQrCodeProvider(borderWidth: 2, format: 'svg'), 'AguaSinCal');
        return new Auth($this->db(), $this->limitador(), $tfa);
    }

    /** URL absoluta del sitio (sin barra final). */
    public function url(string $ruta = ''): string
    {
        $base = rtrim((string) ($this->local('url') ?: $this->config('site')['url']), '/');
        return $base . $ruta;
    }

    public function rutaPanel(): string
    {
        return '/' . trim((string) $this->local('panel_ruta', 'gestion'), '/') . '/';
    }
}
