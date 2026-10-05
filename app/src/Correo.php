<?php
declare(strict_types=1);

namespace AguaSinCal;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envío de emails en texto plano.
 * - transporte "smtp": usa el buzón del hosting (recomendado: avisos@aguasincal.es).
 * - transporte "archivo": guarda cada email como .eml en $dirArchivo (desarrollo y tests).
 */
final class Correo
{
    public function __construct(private readonly array $conf, private readonly string $dirArchivo)
    {
    }

    public function enviar(string $para, string $asunto, string $texto, ?string $responderA = null): bool
    {
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (($this->conf['transporte'] ?? 'archivo') !== 'smtp') {
            return $this->guardarEnArchivo($para, $asunto, $texto, $responderA);
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $this->conf['host'];
            $mail->Port = (int) ($this->conf['puerto'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->conf['usuario'];
            $mail->Password = (string) $this->conf['clave'];
            $mail->SMTPSecure = ($this->conf['seguridad'] ?? 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom((string) ($this->conf['remitente'] ?? $this->conf['usuario']), (string) ($this->conf['remitente_nombre'] ?? 'AguaSinCal'));
            $mail->addAddress($para);
            if ($responderA !== null && filter_var($responderA, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($responderA);
            }
            $mail->Subject = $asunto;
            $mail->Body = $texto;
            $mail->isHTML(false);
            return $mail->send();
        } catch (\Throwable $e) {
            error_log('[correo] Error enviando a ' . $para . ': ' . $e->getMessage());
            return false;
        }
    }

    private function guardarEnArchivo(string $para, string $asunto, string $texto, ?string $responderA): bool
    {
        if (!is_dir($this->dirArchivo)) {
            mkdir($this->dirArchivo, 0750, true);
        }
        $cabeceras = "To: $para\r\nSubject: $asunto\r\n" . ($responderA ? "Reply-To: $responderA\r\n" : '') . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $nombre = sprintf('%s/%s-%s.eml', $this->dirArchivo, gmdate('Ymd-His'), bin2hex(random_bytes(4)));
        return file_put_contents($nombre, $cabeceras . $texto) !== false;
    }
}
