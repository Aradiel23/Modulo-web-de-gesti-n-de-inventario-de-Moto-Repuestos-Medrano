<?php
declare(strict_types=1);
namespace MotoMedrano\Models;

use DomainException;

/** Evento de auditoría de una entrada/salida de inventario. */
final class Movimiento
{
    private array $datos;
    private function __construct(array $datos) { $this->datos = $datos; }

    public static function registrar(Producto $antes, Producto $despues, string $tipo, int $cantidad, string $motivo): self
    {
        $motivo = trim($motivo);
        if (!in_array($tipo, ['entrada', 'salida'], true)) throw new DomainException('Tipo de movimiento inválido.');
        if ($cantidad <= 0 || $cantidad > 1000000 || $motivo === '' || strlen($motivo) > 180) {
            throw new DomainException('Cantidad o motivo inválidos.');
        }
        if ($antes->sku() !== $despues->sku()
            || $despues->stock() !== $antes->stock() + ($tipo === 'entrada' ? $cantidad : -$cantidad)) {
            throw new DomainException('Movimiento inconsistente con la variación de stock.');
        }
        return new self([
            'id' => bin2hex(random_bytes(6)),
            'fecha' => date('Y-m-d H:i:s'),
            'sku' => $antes->sku(),
            'producto' => $antes->nombre(),
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'motivo' => $motivo,
            'antes' => $antes->stock(),
            'despues' => $despues->stock(),
        ]);
    }

    public function tipo(): string { return $this->datos['tipo']; }
    public function cantidad(): int { return $this->datos['cantidad']; }
    public function aArray(): array { return $this->datos; }
}
