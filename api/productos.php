<?php
/**
 * api/productos.php
 * -------------------------------------------------
 * Endpoint REST para CRUD de productos, servicios,
 * apartados con TTL de 3 horas y liberación automática de stock.
 * -------------------------------------------------
 * Plataforma Mova | Fase 2: Backend API
 * -------------------------------------------------
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/db.php';

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Ejecutar rutina de liberación de apartados expirados antes de cualquier operación
expirarApartadosVencidos();

match ($action) {
    'listar'            => handleListarProductos($method),
    'crear'             => handleCrearProducto($method),
    'editar'            => handleEditarProducto($method),
    'eliminar'          => handleEliminarProducto($method),
    'apartar'           => handleCrearApartado($method),
    'apartados'         => handleListarApartados($method),
    'mis_apartados'     => handleListarApartadosCliente($method),
    'ticket'            => handleTicketRecoleccion($method),
    'marcar_pagado'     => handleMarcarApartadoPagado($method),
    'servicios'         => handleListarServicios($method),
    'crear_servicio'    => handleCrearServicio($method),
    'editar_servicio'   => handleEditarServicio($method),
    'eliminar_servicio' => handleEliminarServicio($method),
    default             => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida en productos.php.",
    ], 400),
};

/**
 * Rutina para expirar apartados en efectivo vencidos (> 3 horas)
 * y reintegrar automáticamente el stock disponible.
 */
function expirarApartadosVencidos(): void
{
    try {
        $pdo = getPDO();
        $pdo->beginTransaction();

        // 1. Obtener los apartados pendientes vencidos
        $stmt = $pdo->prepare("
            SELECT id, producto_id, cantidad 
            FROM apartados 
            WHERE estado = 'pendiente' 
              AND tipo_pago = 'efectivo_tienda' 
              AND fecha_expiracion < NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        $expirados = $stmt->fetchAll();

        if (!empty($expirados)) {
            $updateApartado = $pdo->prepare("UPDATE apartados SET estado = 'expirado' WHERE id = ?");
            $restaurarStock = $pdo->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");

            foreach ($expirados as $item) {
                $updateApartado->execute([$item['id']]);
                $restaurarStock->execute([(int) $item['cantidad'], $item['producto_id']]);
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EXPIRAR_APARTADOS_ERROR] ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────
// MÓDULO PRODUCTOS
// ─────────────────────────────────────────────────

function handleListarProductos(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $negocioId = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$negocioId) {
        jsonResponse(['success' => false, 'error' => 'negocio_id es obligatorio.'], 422);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare('
        SELECT id, negocio_id, titulo, descripcion, categoria, precio,
               stock_total, stock_disponible, imagen_url, activo
        FROM productos
        WHERE negocio_id = ? AND activo = 1
        ORDER BY id DESC
    ');
    $stmt->execute([$negocioId]);
    $productos = $stmt->fetchAll();

    jsonResponse([
        'success'   => true,
        'productos' => $productos,
    ]);
}

function handleCrearProducto(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getRequestData();
    $pdo     = getPDO();

    $negocioId = filter_var($body['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $titulo    = trim($body['titulo'] ?? '');
    $desc      = trim($body['descripcion'] ?? '');
    $cat       = trim($body['categoria'] ?? 'general');
    $precio    = filter_var($body['precio'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock     = filter_var($body['stock_total'] ?? null, FILTER_VALIDATE_INT);
    $imagenUrl = trim($body['imagen_url'] ?? '');

    if (!$negocioId || $titulo === '' || $precio === false || $stock === false || $stock < 0) {
        jsonResponse(['success' => false, 'error' => 'Título, precio y stock son campos obligatorios válidos.'], 422);
    }

    // Validar propiedad del negocio y plan de suscripción
    try {
    $pdo->beginTransaction();
    $stmtN = $pdo->prepare('SELECT id, plan_suscripcion FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1 FOR UPDATE');
    $stmtN->execute([$negocioId, $usuario['id']]);
    $negocio = $stmtN->fetch();

    if (!$negocio) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'No autorizado para gestionar este negocio.'], 403);
    }

    // Regla de Negocio D: Plan Básico permite hasta 10 productos activos
    if ($negocio['plan_suscripcion'] === 'basico') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE negocio_id = ? AND activo = 1');
        $countStmt->execute([$negocioId]);
        if ((int) $countStmt->fetchColumn() >= 10) {
            $pdo->rollBack();
            jsonResponse([
                'success' => false,
                'error'   => 'El Plan Básico permite un máximo de 10 productos. Actualiza al Plan Pro para productos ilimitados.',
            ], 403);
        }
    }

    $imagenUrl = procesarImagenProducto($imagenUrl);

    $sql = '
        INSERT INTO productos (negocio_id, titulo, descripcion, categoria, precio, stock_total, stock_disponible, imagen_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ';
    $insert = $pdo->prepare($sql);
    $insert->execute([
        $negocioId,
        $titulo,
        $desc ?: null,
        $cat,
        $precio,
        $stock,
        $stock, // Al crear, el stock_disponible es igual al stock_total
        $imagenUrl ?: null,
    ]);

    $productoId = (int) $pdo->lastInsertId();
    $pdo->commit();

    jsonResponse([
        'success' => true,
        'mensaje' => 'Producto registrado exitosamente.',
        'id'      => $productoId,
    ], 201);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CREAR_PRODUCTO_ERROR] ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'No fue posible registrar el producto.'], 500);
    }
}

function handleEditarProducto(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getRequestData();
    $pdo     = getPDO();

    $id     = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $titulo = trim($body['titulo'] ?? '');
    $desc   = trim($body['descripcion'] ?? '');
    $precio = filter_var($body['precio'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock  = filter_var($body['stock_total'] ?? null, FILTER_VALIDATE_INT);
    $img    = trim($body['imagen_url'] ?? '');

    if (!$id || $titulo === '' || $precio === false || $stock === false || $stock < 0) {
        jsonResponse(['success' => false, 'error' => 'Datos incompletos o inválidos.'], 422);
    }

    // Verificar que el producto pertenezca a un negocio del usuario
    try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        SELECT p.id, p.stock_total, p.stock_disponible, p.imagen_url
        FROM productos p
        JOIN negocios n ON n.id = p.negocio_id
        WHERE p.id = ? AND n.usuario_id = ?
        LIMIT 1 FOR UPDATE
    ');
    $stmt->execute([$id, $usuario['id']]);
    $prod = $stmt->fetch();

    if (!$prod) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'Producto no encontrado o sin permisos.'], 403);
    }

    $img = procesarImagenProducto($img, true) ?? (string) ($prod['imagen_url'] ?? '');

    // Ajustar stock disponible proporcional a la variación de stock_total
    $diff = $stock - (int) $prod['stock_total'];
    $nuevoDisponible = max(0, (int) $prod['stock_disponible'] + $diff);

    $update = $pdo->prepare('
        UPDATE productos 
        SET titulo = ?, descripcion = ?, precio = ?, stock_total = ?, stock_disponible = ?, imagen_url = ?
        WHERE id = ?
    ');
    $update->execute([$titulo, $desc ?: null, $precio, $stock, $nuevoDisponible, $img ?: null, $id]);

    $pdo->commit();

    jsonResponse(['success' => true, 'mensaje' => 'Producto actualizado.']);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[EDITAR_PRODUCTO_ERROR] ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'No fue posible actualizar el producto.'], 500);
    }
}

function handleEliminarProducto(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $id      = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if (!$id) jsonResponse(['success' => false, 'error' => 'ID inválido.'], 422);

    $pdo = getPDO();
    $stmt = $pdo->prepare('
        UPDATE productos p
        JOIN negocios n ON n.id = p.negocio_id
        SET p.activo = 0
        WHERE p.id = ? AND n.usuario_id = ?
    ');
    $stmt->execute([$id, $usuario['id']]);

    jsonResponse(['success' => true, 'mensaje' => 'Producto retirado del catálogo.']);
}

// ─────────────────────────────────────────────────
// MÓDULO APARTADOS
// ─────────────────────────────────────────────────

function handleCrearApartado(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('cliente');
    $body    = getJsonInput();
    $pdo     = getPDO();

    $productoId = filter_var($body['producto_id'] ?? null, FILTER_VALIDATE_INT);
    $negocioId  = filter_var($body['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $tipoPago   = trim($body['tipo_pago'] ?? 'efectivo_tienda');
    $notas      = trim($body['notas_cliente'] ?? '');
    $cantidad   = 1; // Bloquea 1 unidad según regla de negocio 3.A

    if (!$productoId || !$negocioId || !in_array($tipoPago, ['efectivo_tienda', 'online'], true)) {
        jsonResponse(['success' => false, 'error' => 'Datos de apartado incompletos o tipo de pago inválido.'], 422);
    }

    try {
        $pdo->beginTransaction();

        // Verificar negocio y plan
        $stmtN = $pdo->prepare('SELECT plan_suscripcion FROM negocios WHERE id = ? AND activo = 1 LIMIT 1');
        $stmtN->execute([$negocioId]);
        $negocio = $stmtN->fetch();

        if (!$negocio) {
            throw new \RuntimeException('El negocio no está disponible.');
        }

        if ($tipoPago === 'online' && $negocio['plan_suscripcion'] !== 'pro') {
            throw new \RuntimeException('Este negocio no tiene habilitado el pago en línea (Plan Pro requerido).');
        }

        // Consultar producto con bloqueo de fila (FOR UPDATE)
        $stmtP = $pdo->prepare('SELECT id, precio, stock_disponible FROM productos WHERE id = ? AND negocio_id = ? AND activo = 1 FOR UPDATE');
        $stmtP->execute([$productoId, $negocioId]);
        $prod = $stmtP->fetch();

        if (!$prod || (int) $prod['stock_disponible'] < $cantidad) {
            throw new \RuntimeException('El producto se ha agotado o no tiene stock disponible.');
        }

        // Descontar del stock disponible
        $descStock = $pdo->prepare('UPDATE productos SET stock_disponible = stock_disponible - ? WHERE id = ?');
        $descStock->execute([$cantidad, $productoId]);

        // Calcular TTL: 3 horas para efectivo en tienda, 30 minutos para pago online
        $intervalo = ($tipoPago === 'efectivo_tienda') ? '3 HOUR' : '30 MINUTE';
        $precioUnitario = (float) $prod['precio'];
        $montoTotal     = $precioUnitario * $cantidad;

        $insertA = $pdo->prepare("
            INSERT INTO apartados (producto_id, cliente_id, negocio_id, cantidad, tipo_pago, estado, precio_unitario, monto_total, fecha_expiracion, notas_cliente)
            VALUES (?, ?, ?, ?, ?, 'pendiente', ?, ?, DATE_ADD(NOW(), INTERVAL $intervalo), ?)
        ");
        $insertA->execute([
            $productoId,
            $usuario['id'],
            $negocioId,
            $cantidad,
            $tipoPago,
            $precioUnitario,
            $montoTotal,
            $notas ?: null,
        ]);

        $apartadoId = (int) $pdo->lastInsertId();

        $pdo->commit();

        jsonResponse([
            'success'     => true,
            'mensaje'     => 'Apartado registrado exitosamente.',
            'apartado_id' => $apartadoId,
        ], 201);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

function handleListarApartados(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $usuario   = requireAuth('negocio');
    $negocioId = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $pdo       = getPDO();

    // Validar propiedad
    $stmtN = $pdo->prepare('SELECT id FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1');
    $stmtN->execute([$negocioId, $usuario['id']]);
    if (!$stmtN->fetch()) {
        jsonResponse(['success' => false, 'error' => 'No autorizado.'], 403);
    }

    $stmt = $pdo->prepare('
        SELECT a.id, a.producto_id, a.cliente_id, a.cantidad, a.tipo_pago,
               a.estado, a.monto_total, a.fecha_expiracion, a.created_at,
               p.titulo AS titulo_producto,
               u.nombre AS nombre_cliente,
               u.telefono AS telefono_cliente
        FROM apartados a
        JOIN productos p ON p.id = a.producto_id
        JOIN usuarios u ON u.id = a.cliente_id
        WHERE a.negocio_id = ?
        ORDER BY a.id DESC
        LIMIT 50
    ');
    $stmt->execute([$negocioId]);
    $apartados = $stmt->fetchAll();

    jsonResponse(['success' => true, 'apartados' => $apartados]);
}

function handleListarApartadosCliente(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $usuario = requireAuth('cliente');
    $pdo = getPDO();
    $stmt = $pdo->prepare('
        SELECT a.id, a.cantidad, a.tipo_pago, a.estado, a.precio_unitario,
               a.monto_total, a.fecha_expiracion, a.created_at,
               p.titulo AS titulo_producto, n.nombre AS nombre_negocio,
               n.direccion, n.ciudad
        FROM apartados a
        JOIN productos p ON p.id = a.producto_id
        JOIN negocios n ON n.id = a.negocio_id
        WHERE a.cliente_id = ?
        ORDER BY a.id DESC
        LIMIT 100
    ');
    $stmt->execute([$usuario['id']]);
    $apartados = $stmt->fetchAll();
    foreach ($apartados as &$apartado) {
        $apartado['folio_recoleccion'] = folioRecoleccion((int) $apartado['id']);
    }

    jsonResponse(['success' => true, 'apartados' => $apartados]);
}

function handleTicketRecoleccion(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $usuario = requireAuth('cliente');
    $apartadoId = filter_var($_GET['apartado_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$apartadoId) jsonResponse(['success' => false, 'error' => 'apartado_id es obligatorio.'], 422);

    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT a.id, a.cantidad, a.monto_total, a.estado,
               p.titulo AS producto, n.nombre AS negocio,
               n.direccion, n.ciudad
        FROM apartados a
        JOIN productos p ON p.id = a.producto_id
        JOIN negocios n ON n.id = a.negocio_id
        WHERE a.id = ? AND a.cliente_id = ? AND a.estado = 'pagado'
        LIMIT 1
    ");
    $stmt->execute([$apartadoId, $usuario['id']]);
    $ticket = $stmt->fetch();
    if (!$ticket) jsonResponse(['success' => false, 'error' => 'Ticket no encontrado o compra aún no pagada.'], 404);

    $ticket['folio'] = folioRecoleccion((int) $ticket['id']);
    jsonResponse(['success' => true, 'ticket' => $ticket]);
}

function getRequestData(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    return str_contains($contentType, 'multipart/form-data') ? $_POST : getJsonInput();
}

function procesarImagenProducto(string $urlAlternativa = '', bool $opcional = false): ?string
{
    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE) {
        return $urlAlternativa !== '' ? $urlAlternativa : ($opcional ? null : null);
    }

    $archivo = $_FILES['imagen'];
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'No se pudo completar la carga de la imagen.'], 422);
    }
    if ((int) $archivo['size'] > 3 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'La imagen no puede superar 3 MB.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensiones[$mime])) {
        jsonResponse(['success' => false, 'error' => 'Formato inválido. Usa JPEG, PNG o WebP.'], 422);
    }

    $directorio = dirname(__DIR__) . '/uploads/productos';
    if (!is_dir($directorio) && !mkdir($directorio, 0755, true) && !is_dir($directorio)) {
        jsonResponse(['success' => false, 'error' => 'No fue posible preparar el directorio de imágenes.'], 500);
    }

    $nombre = uniqid('prod_', true) . '.' . $extensiones[$mime];
    if (!move_uploaded_file($archivo['tmp_name'], $directorio . '/' . $nombre)) {
        jsonResponse(['success' => false, 'error' => 'No fue posible guardar la imagen.'], 500);
    }
    return 'uploads/productos/' . $nombre;
}

function folioRecoleccion(int $apartadoId): string
{
    return 'REC-' . str_pad((string) $apartadoId, 8, '0', STR_PAD_LEFT);
}

function handleMarcarApartadoPagado(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $id      = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if (!$id) jsonResponse(['success' => false, 'error' => 'ID inválido.'], 422);

    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE apartados a
            JOIN negocios n ON n.id = a.negocio_id
            JOIN productos p ON p.id = a.producto_id
            SET a.estado = 'pagado',
                p.stock_total = GREATEST(0, p.stock_total - a.cantidad)
            WHERE a.id = ?
              AND n.usuario_id = ?
              AND a.estado = 'pendiente'
              AND a.tipo_pago = 'efectivo_tienda'
        ");
        $stmt->execute([$id, $usuario['id']]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('No se pudo actualizar el apartado (ya fue procesado o no pertenece al negocio).');
        }

        $pdo->commit();
        jsonResponse(['success' => true, 'mensaje' => 'Apartado marcado como pagado exitosamente en tienda.']);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

// ─────────────────────────────────────────────────
// MÓDULO SERVICIOS
// ─────────────────────────────────────────────────

function handleListarServicios(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $negocioId = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$negocioId) jsonResponse(['success' => false, 'error' => 'negocio_id es obligatorio.'], 422);

    $pdo  = getPDO();
    $stmt = $pdo->prepare('
        SELECT id, negocio_id, nombre, descripcion, duracion_minutos, precio_estimado, monto_anticipo, activo
        FROM servicios
        WHERE negocio_id = ? AND activo = 1
        ORDER BY id ASC
    ');
    $stmt->execute([$negocioId]);

    jsonResponse(['success' => true, 'servicios' => $stmt->fetchAll()]);
}

function handleCrearServicio(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario   = requireAuth('negocio');
    $body      = getJsonInput();
    $pdo       = getPDO();

    $negocioId = filter_var($body['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $nombre    = trim($body['nombre'] ?? '');
    $desc      = trim($body['descripcion'] ?? '');
    $duracion  = filter_var($body['duracion_minutos'] ?? null, FILTER_VALIDATE_INT);
    $precio    = filter_var($body['precio_estimado'] ?? null, FILTER_VALIDATE_FLOAT);
    $anticipo  = filter_var($body['monto_anticipo'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$negocioId || $nombre === '' || !$duracion || $precio === false) {
        jsonResponse(['success' => false, 'error' => 'Campos de servicio obligatorios o inválidos.'], 422);
    }

    $stmtN = $pdo->prepare('SELECT id FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1');
    $stmtN->execute([$negocioId, $usuario['id']]);
    if (!$stmtN->fetch()) {
        jsonResponse(['success' => false, 'error' => 'No autorizado.'], 403);
    }

    $sql = 'INSERT INTO servicios (negocio_id, nombre, descripcion, duracion_minutos, precio_estimado, monto_anticipo) VALUES (?, ?, ?, ?, ?, ?)';
    $pdo->prepare($sql)->execute([$negocioId, $nombre, $desc ?: null, $duracion, $precio, max(0, $anticipo)]);

    jsonResponse(['success' => true, 'mensaje' => 'Servicio creado exitosamente.'], 201);
}

function handleEditarServicio(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario  = requireAuth('negocio');
    $body     = getJsonInput();
    $pdo      = getPDO();

    $id       = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $nombre   = trim($body['nombre'] ?? '');
    $desc     = trim($body['descripcion'] ?? '');
    $duracion = filter_var($body['duracion_minutos'] ?? null, FILTER_VALIDATE_INT);
    $precio   = filter_var($body['precio_estimado'] ?? null, FILTER_VALIDATE_FLOAT);
    $anticipo = filter_var($body['monto_anticipo'] ?? 0, FILTER_VALIDATE_FLOAT);

    if (!$id || $nombre === '' || !$duracion || $precio === false) {
        jsonResponse(['success' => false, 'error' => 'Datos inválidos.'], 422);
    }

    $stmt = $pdo->prepare('
        UPDATE servicios s
        JOIN negocios n ON n.id = s.negocio_id
        SET s.nombre = ?, s.descripcion = ?, s.duracion_minutos = ?, s.precio_estimado = ?, s.monto_anticipo = ?
        WHERE s.id = ? AND n.usuario_id = ?
    ');
    $stmt->execute([$nombre, $desc ?: null, $duracion, $precio, max(0, $anticipo), $id, $usuario['id']]);

    jsonResponse(['success' => true, 'mensaje' => 'Servicio actualizado.']);
}

function handleEliminarServicio(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $id      = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if (!$id) jsonResponse(['success' => false, 'error' => 'ID inválido.'], 422);

    $pdo = getPDO();
    $stmt = $pdo->prepare('
        UPDATE servicios s
        JOIN negocios n ON n.id = s.negocio_id
        SET s.activo = 0
        WHERE s.id = ? AND n.usuario_id = ?
    ');
    $stmt->execute([$id, $usuario['id']]);

    jsonResponse(['success' => true, 'mensaje' => 'Servicio eliminado.']);
}
