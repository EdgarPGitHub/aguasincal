<?php
declare(strict_types=1);

namespace AguaSinCal;

use PDO;
use RobThree\Auth\TwoFactorAuth;

/** Usuarios del panel: contraseña (argon2id/bcrypt) + TOTP obligatorio. */
final class Auth
{
    public const MAX_INTENTOS = 5;
    public const VENTANA = 900;

    public function __construct(
        private readonly PDO $db,
        private readonly Limitador $limitador,
        private readonly TwoFactorAuth $tfa,
    ) {
    }

    public function hayUsuarios(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM admin_usuarios')->fetchColumn() > 0;
    }

    public function crearUsuario(string $email, string $clave): int
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email no válido');
        }
        if (mb_strlen($clave) < 12) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 12 caracteres');
        }
        $st = $this->db->prepare('INSERT INTO admin_usuarios (email, password_hash, creado) VALUES (?, ?, ?)');
        $st->execute([$email, self::hash($clave), Db::ahora()]);
        return (int) $this->db->lastInsertId();
    }

    public function cambiarClave(int $id, string $clave): void
    {
        if (mb_strlen($clave) < 12) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 12 caracteres');
        }
        $this->db->prepare('UPDATE admin_usuarios SET password_hash = ? WHERE id = ?')->execute([self::hash($clave), $id]);
    }

    /**
     * Comprueba email y contraseña aplicando límite de intentos por IP y por email.
     * @return array{0: ?array, 1: string} [usuario, error]
     */
    public function credenciales(string $email, string $clave, string $ipHash, int $ahora): array
    {
        $email = mb_strtolower(trim($email));
        $claves = ['login:ip:' . $ipHash, 'login:email:' . hash('sha256', $email)];
        foreach ($claves as $k) {
            if ($this->limitador->superado($k, self::MAX_INTENTOS, self::VENTANA, $ahora)) {
                return [null, 'Demasiados intentos. Espera 15 minutos.'];
            }
        }
        $st = $this->db->prepare('SELECT * FROM admin_usuarios WHERE email = ?');
        $st->execute([$email]);
        $usuario = $st->fetch() ?: null;
        // Verificar siempre un hash para no revelar si el email existe por el tiempo de respuesta.
        $hash = $usuario['password_hash'] ?? '$2y$10$b1yJpLyLzGPXrdfadOQnYOzO9LYfQCEdtctfd.tLf4TcMp4bE3.si';
        if (!password_verify($clave, $hash) || $usuario === null) {
            foreach ($claves as $k) {
                $this->limitador->registrar($k, $ahora);
            }
            return [null, 'Email o contraseña incorrectos.'];
        }
        if (password_needs_rehash($usuario['password_hash'], self::algoritmo())) {
            $this->db->prepare('UPDATE admin_usuarios SET password_hash = ? WHERE id = ?')->execute([self::hash($clave), $usuario['id']]);
        }
        return [$usuario, ''];
    }

    public function verificarCodigo(string $secreto, string $codigo, string $ipHash, int $ahora): bool
    {
        $clave = 'totp:ip:' . $ipHash;
        if ($this->limitador->superado($clave, self::MAX_INTENTOS, self::VENTANA, $ahora)) {
            return false;
        }
        $codigo = preg_replace('/\D+/', '', $codigo);
        $ok = $secreto !== '' && strlen($codigo) === 6 && $this->tfa->verifyCode($secreto, $codigo, 1, $ahora);
        if (!$ok) {
            $this->limitador->registrar($clave, $ahora);
        }
        return $ok;
    }

    public function nuevoSecreto(): string
    {
        return $this->tfa->createSecret();
    }

    /** Imagen QR (data URI SVG generado localmente, sin servicios externos). */
    public function qrDataUri(string $email, string $secreto): string
    {
        return $this->tfa->getQRCodeImageAsDataUri($email, $secreto, 220);
    }

    public function activarTotp(int $id, string $secreto): void
    {
        $this->db->prepare('UPDATE admin_usuarios SET totp_secreto = ?, totp_activo = 1 WHERE id = ?')->execute([$secreto, $id]);
    }

    public function registrarAcceso(int $id): void
    {
        $this->db->prepare('UPDATE admin_usuarios SET ultimo_acceso = ? WHERE id = ?')->execute([Db::ahora(), $id]);
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM admin_usuarios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private static function algoritmo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function hash(string $clave): string
    {
        return password_hash($clave, self::algoritmo());
    }
}
