<?php
declare(strict_types=1);
//Lógica de almacenamiento está separada de la interfaz.
require_once __DIR__ . '/src/store.php';
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(float|int $value): string { return 'Bs ' . number_format((float)$value, 2, ',', '.'); }
function go(string $url): never { header('Location: ' . $url, true, 303); exit; }
function flash(string $kind, string $text): void { $_SESSION['flash'] = ['kind'=>$kind,'text'=>$text]; }
function destination(string $page): string { return '?page=' . rawurlencode($page); }

// Procesa los formularios de registrar producto, movimientos y activación.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Solicitud no autorizada (CSRF).'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'guardar_producto':
                save_product($_POST);
                flash('success', 'Producto guardado correctamente.');
                go('?page=productos');
            case 'movimiento':
                $m = save_movement($_POST);
                flash('success', 'Movimiento registrado: ' . $m['sku'] . ' de ' . $m['antes'] . ' a ' . $m['despues'] . ' unidades.');
                go('?page=movimientos');
            case 'alternar':
                toggle_product((string)($_POST['sku'] ?? ''));
                flash('success', 'Estado del producto actualizado.');
                go('?page=productos');
            default: throw new DomainException('Acción no reconocida.');
        }
    } catch (DomainException $exception) {
        flash('error', $exception->getMessage());
        go($action === 'movimiento' ? '?page=movimientos' : '?page=productos');
    }
}

// Datos cargados desde el archivo local.
$db = read_database();
$products = $db['productos'];
$vehicles = $db['vehiculos'];
$movements = $db['movimientos'];
$candidate = (string)($_GET['page'] ?? 'resumen');
$page = in_array($candidate, ['resumen','productos','movimientos','compatibilidad'], true) ? $candidate : 'resumen';
$q = trim((string)($_GET['q'] ?? ''));
$vehicleId = trim((string)($_GET['vehiculo'] ?? ''));
$vehicleSelected = $vehicleId !== '' ? find_vehicle($vehicles, $vehicleId) : null;
$filtered = array_values(array_filter($products, function ($p) use ($q,$vehicleId) {
    $match = $q === '' || stripos($p['sku'].' '.$p['nombre'].' '.$p['categoria'].' '.$p['marca'].' '.$p['ubicacion'], $q) !== false;
    return $match && ($vehicleId === '' || in_array($vehicleId, $p['compatibles'], true));
}));
$active = array_values(array_filter($products, fn($p) => $p['activo']));
$low = array_values(array_filter($active, fn($p) => $p['stock'] <= $p['minimo']));
$unitCount = array_sum(array_map(fn($p) => $p['stock'], $active));
$inventoryValue = array_sum(array_map(fn($p) => $p['stock'] * $p['precio'], $active));
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$edited = isset($_GET['editar']) ? find_product($products, (string)$_GET['editar']) : null;
$empty = ['sku'=>'','nombre'=>'','categoria'=>'','marca'=>'','precio'=>0,'minimo'=>3,'ubicacion'=>'','compatibles'=>[],'equivalentes'=>[],'stock'=>0,'activo'=>true];
$formProduct = $edited ?? $empty;
function vehicle_label(array $vehicles, string $id): string {
    $v = find_vehicle($vehicles, $id);
    return $v ? $v['marca'] . ' ' . $v['modelo'] : $id;
}
function status_tag(array $p): string {
    if (!$p['activo']) return '<span class="pill muted">Inactivo</span>';
    if ($p['stock'] === 0) return '<span class="pill danger">Agotado</span>';
    if ($p['stock'] <= $p['minimo']) return '<span class="pill warning">Stock bajo</span>';
    return '<span class="pill success">Disponible</span>';
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventario | Moto Repuestos Medrano</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="interacciones.css">
<script src="interacciones.js" defer></script>
</head>
<body>
<div class="shell">
<aside class="sidebar">
    <div class="brand"><div class="brand-icon">M</div><div><strong>MEDRANO</strong><small>MOTO REPUESTOS</small></div></div>
    <div class="nav-caption">MÓDULO DE INVENTARIO</div>
    <nav>
       <a class="<?= $page === 'resumen' ? 'current' : '' ?>" href="?page=resumen"><span>◫</span> Panel general</a>
       <a class="<?= $page === 'productos' ? 'current' : '' ?>" href="?page=productos"><span>▣</span> Productos</a>
       <a class="<?= $page === 'movimientos' ? 'current' : '' ?>" href="?page=movimientos"><span>⇅</span> Movimientos</a>
       <a class="<?= $page === 'compatibilidad' ? 'current' : '' ?>" href="?page=compatibilidad"><span>⌕</span> Compatibilidad</a>
    </nav>
    <div class="side-bottom"><span class="pulse"></span>Encargado de Turno<small>Aradiel Pinaicobo Teran</small></div>
</aside>
<main class="main">
  <header class="topbar"><div><span class="overline">MOTO REPUESTOS MEDRANO / INVENTARIO</span><h1><?= e(['resumen'=>'Panel de control','productos'=>'Gestión de productos','movimientos'=>'Entradas y salidas','compatibilidad'=>'Buscar repuestos compatibles'][$page]) ?></h1><p>Control de existencias y consulta de alternativas de repuestos</p></div><div class="badge-top">ARADIEL PINAICOBO</div></header>
  <?php if ($flashMessage): ?><div class="notice <?= e($flashMessage['kind']) ?>" role="status"><?= e($flashMessage['text']) ?></div><?php endif; ?>
  <?php if ($page === 'resumen'): ?>
  <div class="metrics">
    <section class="metric"><small>PRODUCTOS ACTIVOS</small><strong><?= count($active) ?></strong><span>Referencias registradas</span></section>
    <section class="metric"><small>UNIDADES DISPONIBLES</small><strong><?= $unitCount ?></strong><span>Existencias acumuladas</span></section>
    <section class="metric"><small>ALERTAS DE STOCK</small><strong class="red"><?= count($low) ?></strong><span>Al mínimo o por debajo</span></section>
    <section class="metric"><small>VALOR REFERENCIAL</small><strong class="money"><?= money($inventoryValue) ?></strong><span>Cálculo con precios de inventario</span></section>
  </div>
  <div class="two-col">
   <section class="panel"><div class="panel-header"><div><h2>Productos con stock crítico</h2><p>Priorizar reposición o verificar alternativas</p></div><a class="plain-link" href="?page=productos">Ver productos</a></div>
   <?php if (count($low) === 0): ?>
     <div class="empty">No hay productos con stock crítico en este momento.</div>
   <?php else: ?>
     <div class="table-wrap">
       <table class="js-paginated-table" id="tabla-stock-critico" data-paging-name="productos críticos">
         <thead><tr><th>PRODUCTO</th><th>STOCK</th><th>ESTADO</th></tr></thead>
         <tbody>
         <?php foreach ($low as $p): ?>
           <tr>
             <td><b><?= e($p['nombre']) ?></b><small><?= e($p['sku']) ?></small></td>
             <td><strong><?= (int)$p['stock'] ?> / mín. <?= (int)$p['minimo'] ?></strong></td>
             <td><?= status_tag($p) ?></td>
           </tr>
         <?php endforeach; ?>
         </tbody>
       </table>
     </div>
   <?php endif; ?>
   </section>
   <section class="panel"><div class="panel-header"><div><h2>Consulta por motocicleta</h2><p>Búsqueda especializada para atención en mostrador</p></div></div><form method="get" class="search-widget"><input type="hidden" name="page" value="compatibilidad"><label for="veh-dash">Selecciona marca y modelo</label><select id="veh-dash" name="vehiculo" required><option value="">Elegir motocicleta</option><?php foreach ($vehicles as $v): ?><option value="<?= e($v['id']) ?>"><?= e($v['marca'].' '.$v['modelo']) ?></option><?php endforeach; ?></select><button class="btn primary" type="submit">Buscar compatibles</button></form><div class="help-note">Buscar compatibilidad de motocicletas.</div></section>
  </div>
  <section class="panel"><div class="panel-header"><div><h2>Movimientos recientes</h2><p>Trazabilidad de entradas y salidas</p></div><a class="btn outline" href="?page=movimientos">Registrar movimiento</a></div>
    <?php if (!$movements): ?><div class="empty">Todavía no hay movimientos. Registra una entrada o salida para generar la primera evidencia.</div><?php else: ?><div class="table-wrap"><table class="js-paginated-table" id="tabla-recientes" data-paging-name="movimientos recientes"><thead><tr><th>FECHA</th><th>PRODUCTO</th><th>OPERACIÓN</th><th>CAMBIO</th><th>SALDO</th></tr></thead><tbody><?php foreach ($movements as $m): ?><tr><td><?= e($m['fecha']) ?></td><td><?= e($m['sku']) ?></td><td><?= e(ucfirst($m['tipo'])) ?></td><td><?= $m['tipo']==='entrada'?'+':'-' ?><?= (int)$m['cantidad'] ?></td><td><?= (int)$m['despues'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <?php elseif ($page === 'productos'): ?>
  <section class="panel"><div class="panel-header"><div><h2>Catálogo de repuestos</h2><p>Altas, edición y control de stock sin alterar el historial</p></div><a class="btn primary" href="#form-producto">+ Registrar producto</a></div>
   <form class="filters" method="get"><input type="hidden" name="page" value="productos"><input class="search" type="search" name="q" placeholder="Buscar por código, nombre, marca o categoría" value="<?= e($q) ?>"><button class="btn outline">Buscar</button><a class="plain-link" href="?page=productos">Limpiar</a></form>
   <div class="table-wrap"><table class="js-paginated-table" id="tabla-productos" data-paging-name="productos"><thead><tr><th>CÓDIGO / REPUESTO</th><th>CATEGORÍA</th><th>PRECIO</th><th>STOCK</th><th>ESTADO</th><th>ACCIONES</th></tr></thead><tbody><?php foreach ($filtered as $p): ?><tr><td><b><?= e($p['nombre']) ?></b><small><?= e($p['sku'].' · '.$p['marca']) ?></small></td><td><?= e($p['categoria']) ?></td><td><?= money($p['precio']) ?></td><td><strong><?= (int)$p['stock'] ?></strong><small>Mín. <?= (int)$p['minimo'] ?></small></td><td><?= status_tag($p) ?></td><td class="actions"><a href="?page=productos&amp;editar=<?= rawurlencode($p['sku']) ?>#form-producto">Editar</a><form method="post" onsubmit="return confirm('¿Cambiar el estado del producto?')"><?= csrf_field() ?><input type="hidden" name="action" value="alternar"><input type="hidden" name="sku" value="<?= e($p['sku']) ?>"><button class="inline-btn"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div>
  </section>
  <section id="form-producto" class="panel form-panel"><div class="panel-header"><div><h2><?= $edited ? 'Editar producto '.e($edited['sku']) : 'Registrar nuevo repuesto' ?></h2><p>El stock se modifica mediante movimientos, nunca en este formulario</p></div><?php if ($edited): ?><a class="plain-link" href="?page=productos#form-producto">Nuevo registro</a><?php endif; ?></div>
   <form method="post" class="product-form"><?= csrf_field() ?><input type="hidden" name="action" value="guardar_producto"><input type="hidden" name="original" value="<?= e($edited['sku'] ?? '') ?>">
   <div class="form-grid"><label>Código SKU *<input name="sku" value="<?= e($formProduct['sku']) ?>" <?= $edited ? 'readonly':'' ?> placeholder="EJ: FIL-006" required maxlength="30"></label><label>Nombre del repuesto *<input name="nombre" value="<?= e($formProduct['nombre']) ?>" placeholder="Nombre comercial del repuesto" required maxlength="120"></label><label>Categoría *<input name="categoria" value="<?= e($formProduct['categoria']) ?>" placeholder="Frenos, motor, transmisión..." required maxlength="60"></label><label>Marca del repuesto<input name="marca" value="<?= e($formProduct['marca']) ?>" maxlength="70"></label><label>Precio referencial (Bs) *<input type="number" min="0" max="9999999" step="0.01" name="precio" value="<?= e($formProduct['precio']) ?>" required></label><label>Stock mínimo *<input type="number" min="0" step="1" name="minimo" value="<?= e($formProduct['minimo']) ?>" required></label><label>Ubicación física<input name="ubicacion" value="<?= e($formProduct['ubicacion']) ?>" placeholder="Estante A-01" maxlength="60"></label><div class="readonly-card">Stock disponible<strong><?= (int)$formProduct['stock'] ?> unidades</strong><small>Usa “Movimientos” para aumentar o descontar</small></div></div>
   <div class="check-sections">
     <fieldset><legend>Compatibilidad con motocicletas</legend>
       <p>Escribe una marca o modelo y selecciona las motocicletas verificadas por el negocio.</p>
       <div class="searchable-checklist" data-searchable-checklist>
         <label class="checklist-label" for="buscar-compatibles">Buscar motocicleta</label>
         <input id="buscar-compatibles" type="search" class="checklist-search" data-list-filter placeholder="Ej.: Honda CG 125" autocomplete="off">
         <div class="selected-chips" data-selected-chips aria-live="polite"></div>
         <div class="choice-list" data-choice-list>
           <?php foreach ($vehicles as $v): ?>
             <label class="check-item"><input type="checkbox" name="compatibles[]" value="<?= e($v['id']) ?>" <?= in_array($v['id'], $formProduct['compatibles'], true) ? 'checked' : '' ?>> <?= e($v['marca'].' '.$v['modelo']) ?></label>
           <?php endforeach; ?>
         </div>
         <small class="filter-count" data-filter-count></small>
       </div>
     </fieldset>
     <fieldset><legend>Repuestos equivalentes o alternativos</legend>
       <p>Escribe el nombre o código para elegir repuestos alternativos. Verifica las equivalencias antes de registrarlas.</p>
       <div class="searchable-checklist" data-searchable-checklist>
         <label class="checklist-label" for="buscar-equivalentes">Buscar repuesto equivalente</label>
         <input id="buscar-equivalentes" type="search" class="checklist-search" data-list-filter placeholder="Ej.: 1300, bujía, pastillas..." autocomplete="off">
         <div class="selected-chips" data-selected-chips aria-live="polite"></div>
         <div class="choice-list" data-choice-list>
           <?php foreach ($products as $other): if ($other['sku'] === $formProduct['sku']) continue; ?>
             <label class="check-item"><input type="checkbox" name="equivalentes[]" value="<?= e($other['sku']) ?>" <?= in_array($other['sku'], $formProduct['equivalentes'], true) ? 'checked' : '' ?>> <?= e($other['sku'].' - '.$other['nombre']) ?></label>
           <?php endforeach; ?>
         </div>
         <small class="filter-count" data-filter-count></small>
       </div>
     </fieldset>
   </div>
   <button class="btn primary save" type="submit"><?= $edited ? 'Guardar cambios' : 'Crear producto' ?></button></form></section>
  <?php elseif ($page === 'movimientos'): ?>
  <div class="two-col movement-layout"><section class="panel"><div class="panel-header"><div><h2>Registrar entrada o salida</h2><p>El saldo se calcula y el movimiento queda registrado</p></div></div>
   <form class="movement-form" method="post"><?= csrf_field() ?>
     <input type="hidden" name="action" value="movimiento">
     <div class="combo-product" data-product-combo>
       <label for="repuesto-mov">Repuesto *</label>
       <select id="repuesto-mov" class="combo-native" name="sku" required>
         <option value="">Selecciona un producto</option>
         <?php foreach ($active as $p): ?>
           <option value="<?= e($p['sku']) ?>"><?= e($p['sku'].' | '.$p['nombre'].' (stock '.$p['stock'].')') ?></option>
         <?php endforeach; ?>
       </select>
       <div class="combo-custom">
         <input id="buscar-repuesto-mov" type="search" class="combo-input" role="combobox" aria-autocomplete="list" aria-controls="sugerencias-repuestos" aria-expanded="false" autocomplete="off" placeholder="Escribe código o nombre del repuesto...">
         <div id="sugerencias-repuestos" class="combo-suggestions" role="listbox" hidden></div>
         <small class="combo-hint" data-combo-hint>Escribe y elige un producto de la lista.</small>
       </div>
     </div>
     <label>Tipo de operación *<select name="tipo" required><option value="entrada">Entrada: incrementar stock</option><option value="salida">Salida: descontar stock</option></select></label>
     <label>Cantidad *<input type="number" name="cantidad" min="1" max="1000000" step="1" required placeholder="Ej. 5"></label>
     <label>Motivo / referencia *<textarea name="motivo" maxlength="180" rows="3" required placeholder="Ej. Recepción de mercadería de prueba"></textarea></label>
     <button class="btn primary" type="submit">Guardar movimiento</button>
   </form>
  </section><section class="panel legend-panel"><div class="panel-header"><div><h2>Reglas de validación</h2><p>Controles incluidos en el prototipo</p></div></div><div class="rule">✓ No permite cantidades negativas ni decimales</div><div class="rule">✓ Impide salidas si no hay existencias suficientes</div><div class="rule">✓ Conserva saldo anterior, saldo nuevo, fecha y motivo</div><div class="rule">✓ No registra cambios de stock sin un movimiento</div><div class="rule">✓ Controla la solicitud con token CSRF</div><div class="help-note">Una salida representa una disminución de inventario. Este módulo todavía no incluye ventas ni facturación.</div></section></div>
  <section class="panel"><div class="panel-header"><div><h2>Historial de movimientos</h2><p><?= count($movements) ?> transacciones registradas</p></div></div><?php if (!$movements): ?><div class="empty">No hay movimientos registrados todavía.</div><?php else: ?><div class="table-wrap"><table class="js-paginated-table" id="tabla-historial" data-paging-name="movimientos"><thead><tr><th>FECHA</th><th>SKU / REPUESTO</th><th>TIPO</th><th>CANTIDAD</th><th>ANTES</th><th>DESPUÉS</th><th>MOTIVO</th></tr></thead><tbody><?php foreach ($movements as $m): ?><tr><td><?= e($m['fecha']) ?></td><td><b><?= e($m['sku']) ?></b><small><?= e($m['producto']) ?></small></td><td><span class="pill <?= $m['tipo']==='entrada'?'success':'warning' ?>"><?= e(ucfirst($m['tipo'])) ?></span></td><td><?= (int)$m['cantidad'] ?></td><td><?= (int)$m['antes'] ?></td><td><strong><?= (int)$m['despues'] ?></strong></td><td><?= e($m['motivo']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <?php else: ?>
  <section class="panel"><div class="panel-header"><div><h2>Consulta especializada por motocicleta</h2><p>Ver productos compatibles registrados y alternativas disponibles</p></div></div><form class="filters" method="get"><input type="hidden" name="page" value="compatibilidad"><select name="vehiculo"><option value="">Todas las motocicletas</option><?php foreach ($vehicles as $v): ?><option value="<?= e($v['id']) ?>" <?= $vehicleId===$v['id']?'selected':'' ?>><?= e($v['marca'].' '.$v['modelo']) ?></option><?php endforeach; ?></select><input class="search" type="search" name="q" placeholder="Buscar por repuesto, código o categoría" value="<?= e($q) ?>"><button class="btn primary">Consultar</button></form>
  <?php if ($vehicleSelected): ?><div class="filter-note">Mostrando repuestos registrados para <strong><?= e(vehicle_label($vehicles,$vehicleId)) ?></strong> <a href="?page=compatibilidad">Ver todos</a></div><?php endif; ?>
   <div class="compat-grid"><?php foreach ($filtered as $p): ?><article class="compat-card"><div class="compat-top"><span><?= e($p['categoria']) ?></span><?= status_tag($p) ?></div><h3><?= e($p['nombre']) ?></h3><p class="sku"><?= e($p['sku'].' · '.$p['marca']) ?></p><div class="compat-row"><div><small>EXISTENCIAS</small><strong><?= (int)$p['stock'] ?> unid.</strong></div><div><small>PRECIO</small><strong><?= money($p['precio']) ?></strong></div><div><small>UBICACIÓN</small><strong><?= e($p['ubicacion'] ?: 'Sin definir') ?></strong></div></div><div class="compat-footer"><strong>Motocicletas relacionadas</strong><p><?php if (!$p['compatibles']): ?><span class="muted-text">Sin relaciones registradas</span><?php else: foreach ($p['compatibles'] as $vId): ?><span class="chip"><?= e(vehicle_label($vehicles,$vId)) ?></span><?php endforeach; endif; ?></p></div><?php if ($p['equivalentes']): ?><div class="alternatives"><strong>Alternativas registradas</strong><?php foreach ($p['equivalentes'] as $equivCode): $other = find_product($products, $equivCode); if (!$other) continue; ?><p><span><?= e($other['sku']) ?> · <?= e($other['nombre']) ?></span><b><?= (int)$other['stock'] ?> unid.</b></p><?php endforeach; ?></div><?php endif; ?></article><?php endforeach; ?></div>
   <?php if (!$filtered): ?><div class="empty">No se encontraron productos con los filtros seleccionados.</div><?php endif; ?></section>
   <div class="help-note note-outside">Importante: el sistema no determina compatibilidad mecánica por inteligencia artificial. Solo muestra relaciones cargadas y verificadas manualmente.</div>
  <?php endif; ?>

<!-- Pie de Pagina -->
<footer class="footer">Moto Repuestos Medrano · Módulo de Inventario · 2026</footer>
</main></div>

</body></html>
