<?php
declare(strict_types=1);
namespace MotoMedrano\Models;

use DomainException;

/** Entidad de dominio: repuesto identificado por su SKU. */
final class Producto
{
    /** Conserva los metadatos del catálogo. */
    private array $datos;

    private function __construct(array $datos) { $this->datos = $datos; }

    public static function desdeArray(array $datos): self
    {
        $sku = strtoupper(trim((string)($datos['sku'] ?? '')));
        if (!preg_match('/^[A-Z0-9-]{3,30}$/', $sku)) {
            throw new DomainException('SKU inválido en el producto.');
        }
        if (trim((string)($datos['nombre'] ?? '')) === '' || trim((string)($datos['categoria'] ?? '')) === '') {
            throw new DomainException('El repuesto debe tener nombre y categoría.');
        }
        $stock = (int)($datos['stock'] ?? 0);
        $minimo = (int)($datos['minimo'] ?? 0);
        $precio = (float)($datos['precio'] ?? 0);
        if ($stock < 0 || $minimo < 0 || !is_finite($precio) || $precio < 0) {
            throw new DomainException('Stock, mínimo y precio deben ser no negativos.');
        }
        $datos['sku'] = $sku;
        $datos['stock'] = $stock;
        $datos['minimo'] = $minimo;
        $datos['precio'] = $precio;
        $datos['activo'] = (bool)($datos['activo'] ?? true);
        $datos['compatibles'] = array_values((array)($datos['compatibles'] ?? []));
        $datos['equivalentes'] = array_values((array)($datos['equivalentes'] ?? []));
        return new self($datos);
    }

    public function sku(): string { return $this->datos['sku']; }
    public function nombre(): string { return (string)$this->datos['nombre']; }
    public function stock(): int { return $this->datos['stock']; }
    public function minimo(): int { return $this->datos['minimo']; }
    public function estaActivo(): bool { return $this->datos['activo']; }
    public function estaCritico(): bool {
        return $this->estaActivo() && $this->stock() <= $this->minimo();
    }
    public function esCompatibleCon(string $idMotocicleta): bool {
        return in_array($idMotocicleta, $this->datos['compatibles'], true);
    }
    public function coincideBusqueda(string $texto): bool {
        $texto = trim($texto);
        if ($texto === '') return true;
        $campos = ['sku','nombre','categoria','marca','ubicacion'];
        $contenido = implode(' ', array_map(fn($campo) => (string)($this->datos[$campo] ?? ''), $campos));
        return stripos($contenido, $texto) !== false;
    }

    /** Entrada y salida con control de inventario negativo. */
    public function aplicarMovimiento(string $tipo, int $cantidad): self
    {
        if (!$this->estaActivo()) throw new DomainException('No se pueden mover productos inactivos.');
        if (!in_array($tipo, ['entrada','salida'], true)) throw new DomainException('Tipo de movimiento inválido.');
        if ($cantidad <= 0 || $cantidad > 1000000) throw new DomainException('Cantidad inválida.');
        if ($tipo === 'salida' && $cantidad > $this->stock()) {
            throw new DomainException('Stock insuficiente. Disponibles: ' . $this->stock() . ' unidades.');
        }
        $nuevo = $this->datos;
        $nuevo['stock'] = $tipo === 'entrada' ? $this->stock() + $cantidad : $this->stock() - $cantidad;
        return new self($nuevo);
    }

    public function alternarActivo(): self
    {
        $nuevo = $this->datos;
        $nuevo['activo'] = !$nuevo['activo'];
        return new self($nuevo);
    }

    public function aArray(): array { return $this->datos; }
}
