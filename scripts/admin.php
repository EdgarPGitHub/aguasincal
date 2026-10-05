<?php
declare(strict_types=1);

/**
 * Utilidades de administración por línea de comandos (las usa el despliegue).
 *
 *   php scripts/admin.php migrar
 *   php scripts/admin.php crear-usuario email@dominio.es      (contraseña en la variable de entorno ADMIN_CLAVE)
 *   php scripts/admin.php reset-2fa email@dominio.es           (obliga a volver a configurar el 2FA)
 */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$orden = $argv[1] ?? '';

switch ($orden) {
    case 'migrar':
        $aplicadas = AguaSinCal\Db::migrar($app->db(), $app->raiz . '/migrations');
        echo $aplicadas ? 'Migraciones aplicadas: ' . implode(', ', $aplicadas) . "\n" : "Base de datos al día.\n";
        break;

    case 'crear-usuario':
        $email = (string) ($argv[2] ?? '');
        $clave = (string) getenv('ADMIN_CLAVE');
        $auth = $app->auth();
        $st = $app->db()->prepare('SELECT COUNT(*) FROM admin_usuarios WHERE email = ?');
        $st->execute([mb_strtolower(trim($email))]);
        if ((int) $st->fetchColumn() > 0) {
            echo "El usuario ya existe; no se cambia nada.\n";
            break;
        }
        try {
            $auth->crearUsuario($email, $clave);
            echo "Usuario creado. Al entrar por primera vez se configurará la verificación en dos pasos.\n";
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'reset-2fa':
        $st = $app->db()->prepare("UPDATE admin_usuarios SET totp_secreto = '', totp_activo = 0 WHERE email = ?");
        $st->execute([mb_strtolower(trim((string) ($argv[2] ?? '')))]);
        echo $st->rowCount() ? "2FA reiniciado.\n" : "Usuario no encontrado.\n";
        break;

    default:
        fwrite(STDERR, "Uso: php scripts/admin.php migrar | crear-usuario <email> | reset-2fa <email>\n");
        exit(2);
}
