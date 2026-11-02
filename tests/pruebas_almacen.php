<?php
declare(strict_types=1);
/**
 *   C:\xampp\php\php.exe tests\pruebas_almacen.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecuta esta prueba desde la terminal con PHP CLI.');
}

$root = dirname(__DIR__);
$archivoStore = $root . '/src/store.php';
if (!is_file($archivoStore)) {
    fwrite(STDERR, "ERROR: No se encuentra src/store.php. Coloca este archivo en tests/pruebas_almacen.php.\n");
    exit(1);
}

$carpetaTemporal = rtrim(sys_get_temp_dir(), '/\\')
    . DIRECTORY_SEPARATOR . 'medrano-pruebas-' . bin2hex(random_bytes(7));
if (!mkdir($carpetaTemporal, 0700) && !is_dir($carpetaTemporal)) {
    fwrite(STDERR, "ERROR: No se pudo crear la carpeta temporal de pruebas.\n");
    exit(1);
}

$inventarioNuevo = $carpetaTemporal . '/inventario_nuevo.json';
$inventarioAnterior = $carpetaTemporal . '/inventario_existente.json';
$exitos = 0;
$fallos = 0;

function comprobar(bool $condicion, string $descripcion): void {
    global $exitos, $fallos;
    if ($condicion) {
        $exitos++;
        echo '[OK]     ' . $descripcion . PHP_EOL;
    } else {
        $fallos++;
        echo '[FALLO]  ' . $descripcion . PHP_EOL;
    }
}

function rechaza(callable $operacion, string $descripcion): void {
    $rechazada = false;
    try {
        $operacion();
    } catch (DomainException $e) {
        $rechazada = true;
    }
    comprobar($rechazada, $descripcion);
}

function producto_por_sku(array $productos, string $sku): array {
    return find_product($productos, $sku) ?? [];
}

function motocicleta_por_nombre(array $vehiculos, string $marca, string $modelo): array {
    foreach ($vehiculos as $vehiculo) {
        if (($vehiculo['marca'] ?? '') === $marca && ($vehiculo['modelo'] ?? '') === $modelo) {
            return $vehiculo;
        }
    }
    return [];
}

function contiene_relacion(array $productos, string $vehiculoId): bool {
    foreach ($productos as $producto) {
        if (in_array($vehiculoId, $producto['compatibles'] ?? [], true)) return true;
    }
    return false;
}

function contar_filtro(array $productos, string $texto, string $vehiculoId = ''): int {
    return count(array_filter($productos, static function (array $producto) use ($texto, $vehiculoId): bool {
        $busqueda = ($producto['sku'] ?? '') . ' ' . ($producto['nombre'] ?? '')
            . ' ' . ($producto['categoria'] ?? '') . ' ' . ($producto['marca'] ?? '')
            . ' ' . ($producto['ubicacion'] ?? '');
        $coincideTexto = $texto === '' || stripos($busqueda, $texto) !== false;
        $coincideMoto = $vehiculoId === '' || in_array($vehiculoId, $producto['compatibles'] ?? [], true);
        return $coincideTexto && $coincideMoto;
    }));
}

try {
    // La variable de entorno siempre apunta a un archivo temporal ANTES de cargar store.php.
    putenv('MRM_DATA_FILE=' . $inventarioNuevo);
    require_once $archivoStore;

    echo "\n=== 1. CARGA INICIAL DEL INVENTARIO (SIN TOCAR LOS DATOS) ===\n";
    comprobar(database_file() === $inventarioNuevo, 'El inventario de prueba está aislado del inventario real');
    comprobar(!is_file($inventarioNuevo), 'Antes de iniciar, el inventario de prueba no existe');
    $db = read_database();
    comprobar(is_file($inventarioNuevo), 'store.php crea automáticamente un único archivo JSON');
    comprobar(count($db['productos'] ?? []) === 278, 'Se cargan los 278 productos del catálogo');
    comprobar(count($db['vehiculos'] ?? []) === 121, 'Se cargan las 121 referencias de motocicletas');
    comprobar(count($db['movimientos'] ?? []) === 0, 'El historial comienza vacío');
    comprobar(($db['datos_simulados'] ?? false) === true, 'La información de demostración queda identificada');
    comprobar(!empty($db['_catalogo_inicial_instalado']), 'La importación inicial queda identificada por versión');
    $codigos = array_column($db['productos'], 'sku');
    comprobar(count($codigos) === count(array_unique($codigos)), 'No hay códigos SKU repetidos');
    comprobar(count(array_filter($db['productos'], static fn(array $p): bool =>
        ($p['origen'] ?? '') === 'CATALOGO MEDRANO 17-08-2026')) === 278,
        'El origen de los 278 productos queda registrado');
    comprobar(count(array_filter($db['productos'], static fn(array $p): bool =>
        ($p['agotado_catalogo'] ?? false) === true)) === 7,
        'Se conserva la referencia de 7 productos agotados en el catálogo');
    comprobar(count(array_filter($db['productos'], static fn(array $p): bool =>
        ($p['precio_pendiente'] ?? false) === true)) === 12,
        'Se identifican 12 precios pendientes de verificación');

    echo "\n=== 2. STOCK ACTUAL Y ALERTAS ===\n";
    $productos = $db['productos'];
    $stockDemo = array_filter($productos, static fn(array $p): bool => ($p['stock_demo'] ?? false) === true);
    $stockPositivo = array_filter($productos, static fn(array $p): bool => (int)($p['stock'] ?? 0) > 0);
    $stockCritico = array_filter($productos, static fn(array $p): bool =>
        (int)($p['stock'] ?? 0) <= (int)($p['minimo'] ?? 0));
    $stockNormal = array_filter($productos, static fn(array $p): bool =>
        (int)($p['stock'] ?? 0) > (int)($p['minimo'] ?? 0));
    comprobar(count($stockDemo) === 278, 'Los 278 stocks están etiquetados como simulados');
    comprobar(count($stockPositivo) > 0, 'El catálogo muestra existencias positivas para la presentación');
    comprobar(count($stockCritico) > 0, 'Hay productos en estado crítico para mostrar alertas');
    comprobar(count($stockNormal) > 0, 'Hay productos con stock normal');
    comprobar(count(array_filter($productos, static fn(array $p): bool =>
        !is_int($p['stock'] ?? null) || $p['stock'] < 0 || !is_int($p['minimo'] ?? null) || $p['minimo'] < 0)) === 0,
        'Los stocks y mínimos son enteros no negativos');
    comprobar(count(array_filter($productos, static fn(array $p): bool =>
        ($p['stock_confirmado'] ?? true) !== false)) === 0,
        'El stock ficticio no se confunde con conteos físicos confirmados');

    echo "\n=== 3. UBICACIONES (DEPÓSITOS / ESTANTES) ===\n";
    $depositos = [];
    $estantes = [];
    $ubicacionesValidas = true;
    foreach ($productos as $p) {
        if (preg_match('/^Depósito ([A-Z]) - Estante (0[1-9]|[1-3][0-9]|40)$/u', (string)($p['ubicacion'] ?? ''), $m) !== 1) {
            $ubicacionesValidas = false;
            continue;
        }
        $depositos[$m[1]] = true;
        $estantes[$m[2]] = true;
    }
    comprobar($ubicacionesValidas, 'Todas las ubicaciones siguen el formato Depósito X - Estante NN');
    comprobar(count($depositos) === 26, 'Están representados los depósitos de la A a la Z');
    comprobar(count($estantes) === 40, 'Están representados los estantes del 01 al 40');
    comprobar(contar_filtro($productos, 'Depósito A') > 0, 'Se puede buscar un repuesto por depósito');
    comprobar(contar_filtro($productos, 'Estante 01') > 0, 'Se puede buscar un repuesto por estante');
    comprobar(contar_filtro($productos, '520218') === 1, 'Se puede buscar por SKU exacto');

    echo "\n=== 4. MOTOCICLETAS Y COMPATIBILIDAD REFERENCIAL ===\n";
    $vehiculos = $db['vehiculos'];
    $motosConDatos = array_filter($vehiculos, static fn(array $v): bool =>
        is_string($v['id'] ?? null) && ($v['id'] ?? '') !== ''
        && is_string($v['marca'] ?? null) && trim($v['marca']) !== ''
        && is_string($v['modelo'] ?? null) && trim($v['modelo']) !== ''
        && is_numeric($v['cilindrada'] ?? null) && (float)$v['cilindrada'] > 0
        && is_numeric($v['anio'] ?? null) && (int)$v['anio'] >= 1950);
    comprobar(count($motosConDatos) === 121, 'Cada motocicleta tiene marca, modelo, cilindrada y año');
    $idsMotos = array_column($vehiculos, 'id');
    comprobar(count($idsMotos) === count(array_unique($idsMotos)), 'No hay identificadores de motocicleta duplicados');
    $idsValidos = array_fill_keys($idsMotos, true);
    $relacionesValidas = true;
    $asociados = 0;
    foreach ($productos as $p) {
        $relaciones = $p['compatibles'] ?? [];
        if (!is_array($relaciones)) { $relacionesValidas = false; continue; }
        if ($relaciones !== []) $asociados++;
        foreach ($relaciones as $id) {
            if (!isset($idsValidos[$id])) $relacionesValidas = false;
        }
    }
    comprobar($relacionesValidas, 'Todas las relaciones apuntan a motocicletas registradas');
    comprobar($asociados >= 200, 'Más de 200 repuestos tienen relaciones por referencia textual');
    $motoBros = motocicleta_por_nombre($vehiculos, 'Honda', 'Bros 150');
    comprobar($motoBros !== [], 'Honda Bros 150 está registrada entre los modelos');
    $amortiguador = producto_por_sku($productos, '520218');
    comprobar($motoBros !== [] && in_array($motoBros['id'], $amortiguador['compatibles'] ?? [], true),
        'Amortiguador BROS 150 figura relacionado con Honda Bros 150');
    comprobar($motoBros !== [] && contiene_relacion($productos, $motoBros['id'])
        && contar_filtro($productos, '', $motoBros['id']) > 0,
        'El filtro por motocicleta devuelve repuestos asociados');
    comprobar(contar_filtro($productos, '', 'MOTO-INEXISTENTE') === 0,
        'El filtro no devuelve resultados para una moto inexistente');
    comprobar(($db['meta_simulacion']['compatibilidad'] ?? '') !== '',
        'Las compatibilidades referenciales están identificadas como simulación');

    echo "\n=== 5. GESTIÓN DE PRODUCTOS, ALTAS Y EDICIÓN ===\n";
    rechaza(static fn() => save_product([
        'sku'=>'520218', 'nombre'=>'Repetido', 'categoria'=>'Suspensión',
        'precio'=>'30', 'minimo'=>'2'
    ]), 'No permite registrar un SKU duplicado');

    $skuNuevo = 'TES-900';
    $motoId = (string)($motoBros['id'] ?? 'M1');
    $datosNuevo = [
        'sku'=>$skuNuevo, 'original'=>'', 'nombre'=>'Producto de prueba automatizada',
        'categoria'=>'Pruebas', 'marca'=>'Marca demo', 'precio'=>'45.50',
        'minimo'=>'3', 'ubicacion'=>'Depósito Z - Estante 40',
        'compatibles'=>[$motoId], 'equivalentes'=>['520218'],
    ];
    save_product($datosNuevo);
    $nuevo = producto_por_sku(read_database()['productos'], $skuNuevo);
    comprobar($nuevo !== [], 'Permite registrar productos nuevos');
    comprobar(($nuevo['stock'] ?? null) === 0, 'Un producto nuevo inicia con stock cero');
    comprobar(($nuevo['precio'] ?? null) === 45.5, 'Guarda el precio referencial correctamente');
    comprobar(($nuevo['ubicacion'] ?? '') === 'Depósito Z - Estante 40', 'Guarda la ubicación indicada por el usuario');
    comprobar(in_array($motoId, $nuevo['compatibles'] ?? [], true), 'Guarda la motocicleta seleccionada');
    comprobar(in_array('520218', $nuevo['equivalentes'] ?? [], true), 'Guarda el repuesto equivalente seleccionado');

    rechaza(static fn() => save_product([
        'sku'=>'TES-901','nombre'=>'Compatibilidad inválida','categoria'=>'Pruebas',
        'precio'=>'10','minimo'=>'1','compatibles'=>['MOTO-NO-REGISTRADA'],
    ]), 'Rechaza motocicletas que no están registradas');
    rechaza(static fn() => save_product([
        'sku'=>'TES-901','nombre'=>'Equivalencia inválida','categoria'=>'Pruebas',
        'precio'=>'10','minimo'=>'1','equivalentes'=>['NO-EXISTE'],
    ]), 'Rechaza equivalentes desconocidos');
    rechaza(static fn() => save_product([
        'sku'=>'TES-901','nombre'=>'Equivalencia circular','categoria'=>'Pruebas',
        'precio'=>'10','minimo'=>'1','equivalentes'=>['TES-901'],
    ]), 'Rechaza que un producto sea equivalente de sí mismo');
    rechaza(static fn() => save_product([
        'sku'=>'TES 901','nombre'=>'SKU inválido','categoria'=>'Pruebas',
        'precio'=>'10','minimo'=>'1',
    ]), 'Rechaza SKU con caracteres no permitidos');

    $antesEditar = producto_por_sku(read_database()['productos'], '520218');
    save_product([
        'sku'=>'520218', 'original'=>'520218', 'nombre'=>$antesEditar['nombre'],
        'categoria'=>$antesEditar['categoria'], 'marca'=>$antesEditar['marca'],
        'precio'=>'599.00', 'minimo'=>(string)$antesEditar['minimo'],
        'ubicacion'=>$antesEditar['ubicacion'],
        'compatibles'=>$antesEditar['compatibles'], 'equivalentes'=>$antesEditar['equivalentes'],
    ]);
    $despuesEditar = producto_por_sku(read_database()['productos'], '520218');
    comprobar((float)$despuesEditar['precio'] === 599.0, 'La edición actualiza el precio del repuesto');
    comprobar($despuesEditar['stock'] === $antesEditar['stock'], 'Editar un producto no altera sus existencias');
    comprobar(($despuesEditar['origen'] ?? '') === ($antesEditar['origen'] ?? ''),
        'La edición conserva la información de origen del catálogo');
    comprobar(($despuesEditar['stock_demo'] ?? false) === true,
        'La edición conserva la marca del stock de demostración');

    echo "\n=== 6. ENTRADAS, SALIDAS E HISTORIAL ===\n";
    $entrada = save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'entrada', 'cantidad'=>'10', 'motivo'=>'Compra de prueba',
    ]);
    comprobar($entrada['antes'] === 0 && $entrada['despues'] === 10, 'Una entrada suma diez unidades');
    comprobar($entrada['cantidad'] === 10 && $entrada['tipo'] === 'entrada', 'La entrada registra el tipo y cantidad');
    comprobar(!empty($entrada['id']) && !empty($entrada['fecha']) && $entrada['motivo'] === 'Compra de prueba',
        'La entrada registra identificador, fecha y motivo');

    $salida = save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'salida', 'cantidad'=>'4', 'motivo'=>'Venta de prueba',
    ]);
    comprobar($salida['antes'] === 10 && $salida['despues'] === 6, 'Una salida descuenta cuatro unidades');
    comprobar(producto_por_sku(read_database()['productos'], $skuNuevo)['stock'] === 6,
        'El stock actual coincide con los movimientos realizados');
    $movimientos = read_database()['movimientos'];
    comprobar(count($movimientos) === 2, 'El historial conserva los dos movimientos válidos');
    comprobar($movimientos[0]['id'] === $salida['id'], 'Los movimientos recientes aparecen primero');

    rechaza(static fn() => save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'salida', 'cantidad'=>'999', 'motivo'=>'Stock insuficiente',
    ]), 'Impide salidas con stock insuficiente');
    rechaza(static fn() => save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'entrada', 'cantidad'=>'0', 'motivo'=>'Cantidad incorrecta',
    ]), 'Impide registrar cantidad cero');
    rechaza(static fn() => save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'entrada', 'cantidad'=>'1.5', 'motivo'=>'Cantidad decimal',
    ]), 'Impide registrar cantidades decimales');
    rechaza(static fn() => save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'otro', 'cantidad'=>'1', 'motivo'=>'Tipo incorrecto',
    ]), 'Rechaza tipos de movimiento desconocidos');
    rechaza(static fn() => save_movement([
        'sku'=>'SKU-INVALIDO', 'tipo'=>'entrada', 'cantidad'=>'1', 'motivo'=>'Inexistente',
    ]), 'Rechaza movimientos de SKU inexistentes');
    comprobar(count(read_database()['movimientos']) === 2, 'Los movimientos rechazados no alteran el historial');
    comprobar(producto_por_sku(read_database()['productos'], $skuNuevo)['stock'] === 6,
        'Los movimientos rechazados no alteran las existencias');

    toggle_product($skuNuevo);
    comprobar(producto_por_sku(read_database()['productos'], $skuNuevo)['activo'] === false,
        'Permite desactivar un producto');
    rechaza(static fn() => save_movement([
        'sku'=>$skuNuevo, 'tipo'=>'entrada', 'cantidad'=>'1', 'motivo'=>'Producto inactivo',
    ]), 'Bloquea movimientos sobre productos inactivos');
    toggle_product($skuNuevo);
    comprobar(producto_por_sku(read_database()['productos'], $skuNuevo)['activo'] === true,
        'Permite reactivar un producto');

    echo "\n=== 7. ACTUALIZACIÓN SIN PERDER DATOS EXISTENTES ===\n";
    // Segundo inventario temporal que simula una instalación anterior del usuario.
    $registroExistente = $antesEditar;
    $registroExistente['stock'] = 0;
    $registroExistente['stock_confirmado'] = false;
    $registroExistente['stock_demo'] = false;
    $registroExistente['minimo'] = 2;
    $registroExistente['precio'] = 777.0;
    $registroExistente['ubicacion'] = 'Depósito Z - Estante 40';
    $registroExistente['ubicacion_demo'] = false;
    $registroExistente['compatibles'] = ['M1'];
    $registroExistente['compatibilidad_demo'] = false;
    $viejo = [
        'version'=>1, 'demo'=>false,
        'productos'=>[
            $registroExistente,
            ['sku'=>'PROPIO-001','nombre'=>'Producto añadido por el usuario','categoria'=>'Pruebas',
             'marca'=>'Local','precio'=>99.0,'stock'=>7,'minimo'=>2,'ubicacion'=>'Almacén propio',
             'compatibles'=>[],'equivalentes'=>[],'activo'=>true],
        ],
        'vehiculos'=>[['id'=>'M1','marca'=>'Honda','modelo'=>'CG 125']],
        'movimientos'=>[[
            'id'=>'movimiento-viejo', 'fecha'=>'2026-10-01 12:00:00','sku'=>'520218',
            'producto'=>$registroExistente['nombre'], 'tipo'=>'salida', 'cantidad'=>1,
            'motivo'=>'Operación anterior','antes'=>1,'despues'=>0,
        ]],
    ];
    file_put_contents($inventarioAnterior, json_encode($viejo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    putenv('MRM_DATA_FILE=' . $inventarioAnterior);
    $dbMigrada = read_database();
    comprobar(count($dbMigrada['productos']) === 279, 'Se agregan los 277 SKU del catálogo que faltaban');
    comprobar(count($dbMigrada['vehiculos']) === 121, 'Se completan las motocicletas que faltaban');
    comprobar(count($dbMigrada['movimientos']) === 1, 'Se conserva el historial que existía antes de actualizar');
    $repuestoPrevio = producto_por_sku($dbMigrada['productos'], '520218');
    comprobar($repuestoPrevio['stock'] === 0, 'No se inventa stock si ese SKU tiene un movimiento anterior');
    comprobar((float)$repuestoPrevio['precio'] === 777.0, 'No se cambia el precio editado por el usuario');
    comprobar($repuestoPrevio['ubicacion'] === 'Depósito Z - Estante 40',
        'Se respeta una ubicación manual preexistente');
    comprobar($repuestoPrevio['compatibles'] === ['M1'], 'Se conservan relaciones manuales anteriores');
    comprobar(producto_por_sku($dbMigrada['productos'], 'PROPIO-001')['stock'] === 7,
        'Se conserva un producto propio con su saldo');
    comprobar(producto_por_sku($dbMigrada['productos'], '280046') !== [],
        'Se agrega el último repuesto del catálogo si no estaba registrado');
    comprobar(isset(motocicleta_por_nombre($dbMigrada['vehiculos'], 'Honda', 'CG 125')['cilindrada']),
        'Se completa la cilindrada de motos anteriores cuando falta');
    $contenidoAntesDeReleer = file_get_contents($inventarioAnterior);
    $dbMigradaDos = read_database();
    comprobar(file_get_contents($inventarioAnterior) === $contenidoAntesDeReleer
        && count($dbMigradaDos['productos']) === count($dbMigrada['productos']),
        'Al volver a abrir el sistema no se duplican registros');

} catch (Throwable $error) {
    $fallos++;
    echo '\n[ERROR]  ' . $error::class . ': ' . $error->getMessage() . PHP_EOL;
} finally {
    foreach ([$inventarioNuevo, $inventarioAnterior] as $temporal) {
        if (is_file($temporal)) unlink($temporal);
    }
    if (is_dir($carpetaTemporal)) rmdir($carpetaTemporal);
    putenv('MRM_DATA_FILE');
}

echo PHP_EOL . '===================================================' . PHP_EOL;
echo 'RESULTADO: ' . $exitos . ' pruebas superadas; ' . $fallos . ' fallos.' . PHP_EOL;
echo 'IMPORTANTE: No se modificó el inventario de producción.' . PHP_EOL;
echo '===================================================' . PHP_EOL;
exit($fallos === 0 ? 0 : 1);
