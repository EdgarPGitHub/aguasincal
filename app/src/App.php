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

    /** Lee config/{nombre}.php del repositorio (cacheado). */
    public function config(string $nombre): array
    {
        return $this->configs[$nombre] ??= require $this->raiz . '/config/' . $nombre . '.php';
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
        if (strlen($clave) < 32) {
            if ($this->esProduccion()) {
                throw new \RuntimeException('Falta app_key (mínimo 32 caracteres) en config.local.php');
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
