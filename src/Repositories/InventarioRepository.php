<?php
declare(strict_types=1);
namespace MotoMedrano\Repositories;

use RuntimeException;

/** Persistencia JSON con flock: una sola fuente de datos, sin MySQL. */
final class InventarioRepository
{
    private string $ruta;
    public function __construct(string $ruta) { $this->ruta = $ruta; }
    public function ruta(): string { return $this->ruta; }

    public function leer(): array
    {
        $fp = @fopen($this->ruta, 'r');
        if ($fp === false) throw new RuntimeException('No se pudo abrir el almacén de datos.');
        try {
            if (!flock($fp, LOCK_SH)) throw new RuntimeException('No se pudo bloquear para lectura.');
            $contenido = stream_get_contents($fp);
            if ($contenido === false) throw new RuntimeException('No se pudo leer el almacén.');
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($datos)) throw new RuntimeException('Datos no válidos.');
            return $datos;
        } finally { flock($fp, LOCK_UN); fclose($fp); }
    }

    /** Mantiene el bloqueo exclusivo durante la modificación completa. */
    public function modificar(callable $cambio): void
    {
        $fp = @fopen($this->ruta, 'c+');
        if ($fp === false) throw new RuntimeException('No se pudo abrir el almacén para escritura.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('No se pudo bloquear para escritura.');
            rewind($fp);
            $contenido = stream_get_contents($fp);
            if ($contenido === false) throw new RuntimeException('No se pudo leer el almacén para editar.');
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($datos)) throw new RuntimeException('Datos no válidos.');
            $cambio($datos);
            $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            rewind($fp);
            if (!ftruncate($fp, 0)) throw new RuntimeException('No se pudo preparar la escritura.');
            // fwrite puede completar menos bytes que los solicitados: comprobarlo.
            $escritos = 0;
            $total = strlen($json);
            while ($escritos < $total) {
                $n = fwrite($fp, substr($json, $escritos));
                if ($n === false || $n === 0) throw new RuntimeException('No se pudieron guardar los cambios.');
                $escritos += $n;
            }
            fflush($fp);
        } finally { flock($fp, LOCK_UN); fclose($fp); }
    }
}
