<?php
declare(strict_types=1);
/** Prueba orientada a objetos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }

use MotoMedrano\Models\Producto;
use MotoMedrano\Models\Motocicleta;
use MotoMedrano\Models\Movimiento;
use MotoMedrano\Repositories\InventarioRepository;

$ruta = sys_get_temp_dir() . '/medrano-modelos-' . bin2hex(random_bytes(8)) . '.json';
putenv('MRM_DATA_FILE=' . $ruta);
require_once dirname(__DIR__) . '/src/store.php';
$ok = 0; $fallo = 0;
function verificar(bool $condicion, string $titulo): void {
    global $ok, $fallo;
    if ($condicion) { $ok++; echo '[OK]    ' . $titulo . PHP_EOL; }
    else { $fallo++; echo '[FALLO] ' . $titulo . PHP_EOL; }
}
function rechazar(callable $funcion, string $titulo): void {
    try { $funcion(); verificar(false, $titulo); }
    catch (DomainException $ex) { verificar(true, $titulo); }
}
try {
    $db = read_database();
    verificar(count($db['productos']) === 278, 'Carga 278 productos desde la semilla');
    verificar(count($db['vehiculos']) === 121, 'Carga 121 modelos de motocicleta');
    verificar(database_file() === $ruta, 'Usa solo inventario temporal');
    $producto = Producto::desdeArray($db['productos'][0]);
    verificar($producto instanceof Producto, 'Producto representa una entidad de dominio');
    verificar($producto->sku() !== '', 'Producto posee identificador SKU');
    verificar($producto->coincideBusqueda($producto->sku()), 'La entidad puede buscar por SKU');
    verificar($producto->coincideBusqueda('Depósito'), 'La entidad puede buscar por ubicación');
    verificar($producto->estaActivo(), 'Se conserva el estado activo');
    $mas = $producto->aplicarMovimiento('entrada', 5);
    verificar($mas->stock() === $producto->stock() + 5, 'Entrada agrega existencias');
    verificar($producto->stock() === (int)$db['productos'][0]['stock'], 'La entidad original es inmutable');
    $menos = $mas->aplicarMovimiento('salida', 2);
    verificar($menos->stock() === $producto->stock() + 3, 'Salida descuenta existencias');
    rechazar(fn() => $producto->aplicarMovimiento('salida', $producto->stock() + 1), 'Impide salidas con saldo insuficiente');
    rechazar(fn() => $producto->aplicarMovimiento('entrada', 0), 'Rechaza cantidad cero');
    rechazar(fn() => $producto->aplicarMovimiento('otro', 1), 'Rechaza tipos desconocidos');
    $apagado = $producto->alternarActivo();
    verificar(!$apagado->estaActivo(), 'Desactiva un producto');
    rechazar(fn() => $apagado->aplicarMovimiento('entrada', 1), 'Bloquea movimientos de productos inactivos');
    verificar(isset($producto->aArray()['origen']), 'Producto conserva metadatos originales del catálogo');

    $moto = Motocicleta::desdeArray($db['vehiculos'][0]);
    verificar($moto instanceof Motocicleta, 'Motocicleta es un modelo de dominio');
    verificar($moto->id() !== '' && $moto->marca() !== '' && $moto->modelo() !== '', 'Modelo guarda ID, marca y modelo');
    verificar($moto->cilindrada() !== null && $moto->cilindrada() > 0, 'Modelo guarda cilindrada');
    verificar($moto->anio() !== null && $moto->anio() >= 1900, 'Modelo guarda año referencial');
    verificar(str_contains($moto->etiqueta(), $moto->marca()), 'Modelo genera etiqueta legible');

    $mov = Movimiento::registrar($producto, $mas, 'entrada', 5, 'Prueba de modelos');
    verificar($mov instanceof Movimiento, 'Movimiento es una entidad de dominio');
    verificar($mov->tipo() === 'entrada' && $mov->cantidad() === 5, 'Movimiento conserva tipo y cantidad');
    verificar($mov->aArray()['antes'] === $producto->stock(), 'Movimiento registra saldo anterior');
    verificar($mov->aArray()['despues'] === $mas->stock(), 'Movimiento registra saldo posterior');
    rechazar(fn() => Movimiento::registrar($producto, $mas, 'entrada', 4, 'Inconsistente'), 'Movimiento rechaza saldos incoherentes');

    $repo = new InventarioRepository($ruta);
    verificar($repo->ruta() === $ruta, 'Repositorio utiliza la ruta correcta');
    verificar(count($repo->leer()['productos']) === 278, 'Repositorio lee archivo JSON');
    $repo->modificar(static function (&$datos): void { $datos['test_modelos'] = 'persistencia'; });
    verificar($repo->leer()['test_modelos'] === 'persistencia', 'Repositorio guarda bajo bloqueo');
    verificar(find_product($repo->leer()['productos'], $producto->sku()) !== null, 'Fachada usa modelo Producto');
    verificar(find_vehicle($repo->leer()['vehiculos'], $moto->id()) !== null, 'Fachada usa modelo Motocicleta');

    // Flujo completo: fachada -> modelo -> repositorio -> JSON.
    $inicio = (int)find_product(read_database()['productos'], $producto->sku())['stock'];
    $registro = save_movement(['sku'=>$producto->sku(), 'tipo'=>'entrada', 'cantidad'=>'2', 'motivo'=>'Prueba integrada POO']);
    verificar($registro['antes'] === $inicio && $registro['despues'] === $inicio + 2,
        'Integración store.php / Producto / Movimiento / InventarioRepository');
    verificar((int)find_product(read_database()['productos'], $producto->sku())['stock'] === $inicio + 2,
        'El JSON refleja la entrada real');

    echo "\nModelos POO: {$ok} correctas, {$fallo} fallidas.\n";
} finally {
    if (is_file($ruta)) unlink($ruta);
    putenv('MRM_DATA_FILE');
}
exit($fallo > 0 ? 1 : 0);
