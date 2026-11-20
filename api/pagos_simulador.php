<?php
/**
 * api/pagos_simulador.php
 * -------------------------------------------------
 * Simulador de pasarela de pagos (Mercado Pago / Stripe)
 * para acreditar compras online, apartados Click & Collect
 * y recargas de monedero en entorno local de pruebas.
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

match ($action) {
    'pagar_apartado'   => handlePagarApartado($method),
    'recargar_monedero'=> handleRecargarMonedero($method),
    default            => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida en pagos_simulador.php.",
    ], 400),
};

/**
 * POST /api/pagos_simulador.php?action=pagar_apartado
 * Simula la confirmación exitosa de un pago en línea (Click & Collect)
 * actualizando el apartado a 'pagado', descontando stock definitivo y generando folio.
 */
function handlePagarApartado(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('cliente');
    $body    = getJsonInput();
    $pdo     = getPDO();

    $apartadoId = filter_var($body['apartado_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$apartadoId) jsonResponse(['success' => false, 'error' => 'apartado_id es obligatorio.'], 422);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT a.id, a.producto_id, a.monto_total, a.cantidad, a.estado, a.tipo_pago, a.negocio_id,
                   n.usuario_id AS dueno_negocio_id, n.nombre AS nombre_negocio,
                   n.direccion, n.ciudad, p.titulo AS nombre_producto
            FROM apartados a
            JOIN negocios n ON n.id = a.negocio_id
            JOIN productos p ON p.id = a.producto_id
            WHERE a.id = ? AND a.cliente_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$apartadoId, $usuario['id']]);
        $apartado = $stmt->fetch();

        if (!$apartado) {
            throw new \RuntimeException('Apartado no encontrado o no pertenece a tu cuenta.');
        }

        if ($apartado['estado'] !== 'pendiente') {
            throw new \RuntimeException("El apartado ya se encuentra en estado '{$apartado['estado']}'.");
        }

        if ($apartado['tipo_pago'] !== 'online') {
            throw new \RuntimeException('Este apartado debe pagarse en efectivo directamente en el negocio.');
        }

        // Marcar como pagado y descontar del inventario total definitivo
        $pdo->prepare("UPDATE apartados SET estado = 'pagado' WHERE id = ?")->execute([$apartadoId]);
        $pdo->prepare("UPDATE productos SET stock_total = GREATEST(0, stock_total - ?) WHERE id = ?")
            ->execute([(int) $apartado['cantidad'], $apartado['producto_id']]);

        // Generar identificador de transacción simulado (ej: SIM-PAY-XXXXX)
        $refPago = 'SIM-PAY-' . strtoupper(bin2hex(random_bytes(4)));

        // Acreditar al monedero del dueño del negocio
        $monto = (float) $apartado['monto_total'];
        $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
            ->execute([$monto, $apartado['dueno_negocio_id']]);

        // Registrar en movimientos_ledger
        $pdo->prepare("
            INSERT INTO movimientos_ledger (apartado_id, usuario_id, tipo, monto, descripcion, referencia_pago)
            VALUES (?, ?, 'pago_online', ?, ?, ?)
        ")->execute([
            $apartadoId,
            $apartado['dueno_negocio_id'],
            $monto,
            "Pago en línea completado (Click & Collect) para apartado #$apartadoId",
            $refPago,
        ]);

        $pdo->commit();

        jsonResponse([
            'success'         => true,
            'mensaje'         => '¡Pago simulado con éxito! Tu ticket de recolección está listo.',
            'referencia_pago' => $refPago,
            'ticket'          => [
                'apartado_id' => $apartadoId,
                'folio'       => 'REC-' . str_pad((string) $apartadoId, 8, '0', STR_PAD_LEFT),
                'negocio'     => $apartado['nombre_negocio'],
                'direccion'   => $apartado['direccion'],
                'ciudad'      => $apartado['ciudad'],
                'producto'    => $apartado['nombre_producto'],
                'cantidad'    => (int) $apartado['cantidad'],
                'monto'       => $monto,
                'fecha_pago'  => date('Y-m-d H:i:s'),
            ],
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/**
 * POST /api/pagos_simulador.php?action=recargar_monedero
 * Recarga de saldo ficticio al monedero del cliente para pruebas.
 */
function handleRecargarMonedero(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth();
    $body    = getJsonInput();
    $monto   = filter_var($body['monto'] ?? null, FILTER_VALIDATE_FLOAT);

    if (!$monto || $monto <= 0) {
        jsonResponse(['success' => false, 'error' => 'El monto a recargar debe ser mayor a 0.'], 422);
    }

    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
            ->execute([$monto, $usuario['id']]);
        $pdo->commit();

        jsonResponse([
            'success' => true,
            'mensaje' => "Recarga de $$monto MXN acreditada a tu monedero.",
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => 'No fue posible acreditar la recarga.'], 500);
    }
}
