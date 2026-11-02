<?php
declare(strict_types=1);
namespace MotoMedrano\Models;

use DomainException;

/** Entidad que representa una referencia de motocicleta del catálogo. */
final class Motocicleta
{
    private array $datos;

    private function __construct(array $datos) { $this->datos = $datos; }

    public static function desdeArray(array $datos): self
    {
        foreach (['id','marca','modelo'] as $campo) {
            if (trim((string)($datos[$campo] ?? '')) === '') {
                throw new DomainException('La motocicleta requiere ' . $campo . '.');
            }
        }
        if (isset($datos['cilindrada']) && (int)$datos['cilindrada'] < 0) {
            throw new DomainException('Cilindrada inválida.');
        }
        $datos['id'] = (string)$datos['id'];
        $datos['marca'] = (string)$datos['marca'];
        $datos['modelo'] = (string)$datos['modelo'];
        return new self($datos);
    }

    public function id(): string { return $this->datos['id']; }
    public function marca(): string { return $this->datos['marca']; }
    public function modelo(): string { return $this->datos['modelo']; }
    public function cilindrada(): ?int {
        return isset($this->datos['cilindrada']) ? (int)$this->datos['cilindrada'] : null;
    }
    public function anio(): ?int {
        return isset($this->datos['anio']) ? (int)$this->datos['anio'] : null;
    }
    public function etiqueta(): string { return $this->marca() . ' ' . $this->modelo(); }
    public function aArray(): array { return $this->datos; }
}
