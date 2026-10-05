<?php
declare(strict_types=1);

namespace AguaSinCal;

final class Provincias
{
    /** @param array<string, array{nombre:string, slug:string, ccaa:string}> $datos */
    public function __construct(private readonly array $datos)
    {
    }

    /** Código INE de provincia a partir de un CP español de 5 cifras, o null si no es válido. */
    public function desdeCp(string $cp): ?string
    {
        $cp = trim($cp);
        if (!preg_match('/^\d{5}$/', $cp)) {
            return null;
        }
        $codigo = substr($cp, 0, 2);
        return isset($this->datos[$codigo]) ? $codigo : null;
    }

    public function existe(string $codigo): bool
    {
        return isset($this->datos[$codigo]);
    }

    public function nombre(string $codigo): string
    {
        return $this->datos[$codigo]['nombre'] ?? $codigo;
    }

    public function slug(string $codigo): string
    {
        return $this->datos[$codigo]['slug'] ?? $codigo;
    }

    /** @return array<string, array{nombre:string, slug:string, ccaa:string}> */
    public function todas(): array
    {
        return $this->datos;
    }
}
