<?php
declare(strict_types=1);

namespace AguaSinCal\Build;

/** Errores (bloquean el despliegue) y avisos (se muestran pero no bloquean) del generador. */
final class Informe
{
    /** @var list<string> */
    public array $errores = [];
    /** @var list<string> */
    public array $avisos = [];
    /** @var array<string, int|string> */
    public array $resumen = [];

    public function error(string $mensaje): void
    {
        $this->errores[] = $mensaje;
    }

    public function aviso(string $mensaje): void
    {
        $this->avisos[] = $mensaje;
    }

    public function ok(): bool
    {
        return $this->errores === [];
    }
}
