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
    'metodos_pago'     => handleListarMetodosPago($method),
    'agregar_tarjeta'  => handleAgregarTarjeta($method),
    'eliminar_tarjeta' => handleEliminarTarjeta($method),
    default            => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida en pagos_simulador.php.",
    ], 400),
};

function handleListarMetodosPago(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);
    $usuario = requireAuth('cliente');
    $stmt = getPDO()->prepare('SELECT id, marca, ultimos4, titular, exp_mes, exp_anio, predeterminada FROM metodos_pago WHERE usuario_id = ? AND activo = 1 ORDER BY predeterminada DESC, id DESC');
    $stmt->execute([$usuario['id']]);
    jsonResponse(['success' => true, 'metodos' => $stmt->fetchAll()]);
}

function handleAgregarTarjeta(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);
    $usuario = requireAuth('cliente');
    $body = getJsonInput();
    $numero = preg_replace('/\D+/', '', (string) ($body['numero'] ?? ''));
    $titular = trim((string) ($body['titular'] ?? ''));
    $mes = filter_var($body['exp_mes'] ?? null, FILTER_VALIDATE_INT);
    $anio = filter_var($body['exp_anio'] ?? null, FILTER_VALIDATE_INT);
    $cvv = preg_replace('/\D+/', '', (string) ($body['cvv'] ?? ''));
    $actual = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));

    if (!in_array(strlen($numero), [15, 16], true) || !validarLuhn($numero)) {
        jsonResponse(['success' => false, 'error' => 'El número de tarjeta de prueba no es válido.'], 422);
    }
    if (mb_strlen($titular) < 3 || mb_strlen($titular) > 120) {
        jsonResponse(['success' => false, 'error' => 'Escribe el nombre del titular.'], 422);
    }
    if ($mes === false || $mes < 1 || $mes > 12 || $anio === false || $anio < (int) $actual->format('Y') || $anio > (int) $actual->format('Y') + 20) {
        jsonResponse(['success' => false, 'error' => 'La fecha de vencimiento no es válida.'], 422);
    }
    if ($anio === (int) $actual->format('Y') && $mes < (int) $actual->format('n')) {
        jsonResponse(['success' => false, 'error' => 'La tarjeta está vencida.'], 422);
    }
    $marca = detectarMarca($numero);
    $cvvEsperado = $marca === 'American Express' ? 4 : 3;
    if (strlen($cvv) !== $cvvEsperado) {
        jsonResponse(['success' => false, 'error' => "El código de seguridad debe tener $cvvEsperado dígitos."], 422);
    }

    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $count = $pdo->prepare('SELECT id FROM metodos_pago WHERE usuario_id = ? AND activo = 1 FOR UPDATE');
        $count->execute([$usuario['id']]);
        $total = count($count->fetchAll());
        if ($total >= 5) throw new RuntimeException('Puedes guardar hasta 5 tarjetas de prueba.');
        $predeterminada = $total === 0 ? 1 : 0;
        $insert = $pdo->prepare('INSERT INTO metodos_pago (usuario_id, marca, ultimos4, titular, exp_mes, exp_anio, token_simulado, predeterminada) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$usuario['id'], $marca, substr($numero, -4), mb_strtoupper($titular), $mes, $anio, 'tok_sim_' . bin2hex(random_bytes(16)), $predeterminada]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        jsonResponse(['success' => true, 'mensaje' => 'Tarjeta de prueba agregada de forma segura.', 'metodo' => ['id' => $id, 'marca' => $marca, 'ultimos4' => substr($numero, -4), 'predeterminada' => (bool) $predeterminada]], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

function handleEliminarTarjeta(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);
    $usuario = requireAuth('cliente');
    $id = filter_var(getJsonInput()['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) jsonResponse(['success' => false, 'error' => 'Tarjeta inválida.'], 422);
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE metodos_pago SET activo = 0, predeterminada = 0 WHERE id = ? AND usuario_id = ? AND activo = 1');
    $stmt->execute([$id, $usuario['id']]);
    if (!$stmt->rowCount()) jsonResponse(['success' => false, 'error' => 'Tarjeta no encontrada.'], 404);
    $pdo->prepare('UPDATE metodos_pago SET predeterminada = 1 WHERE usuario_id = ? AND activo = 1 ORDER BY id DESC LIMIT 1')->execute([$usuario['id']]);
    jsonResponse(['success' => true, 'mensaje' => 'Tarjeta eliminada.']);
}

function detectarMarca(string $numero): string
{
    if (preg_match('/^4/', $numero)) return 'Visa';
    if (preg_match('/^(5[1-5]|2[2-7])/', $numero)) return 'Mastercard';
    if (preg_match('/^3[47]/', $numero)) return 'American Express';
    return 'Tarjeta';
}

function validarLuhn(string $numero): bool
{
    $suma = 0;
    $alternar = false;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $n = (int) $numero[$i];
        if ($alternar) { $n *= 2; if ($n > 9) $n -= 9; }
        $suma += $n;
        $alternar = !$alternar;
    }
    return $suma > 0 && $suma % 10 === 0;
}

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

        $tarjeta = $pdo->prepare('SELECT id FROM metodos_pago WHERE usuario_id = ? AND activo = 1 ORDER BY predeterminada DESC, id DESC LIMIT 1');
        $tarjeta->execute([$usuario['id']]);
        if (!$tarjeta->fetch()) throw new \RuntimeException('Agrega una tarjeta de prueba antes de completar el pago.');

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
        $tarjeta = $pdo->prepare('SELECT id FROM metodos_pago WHERE usuario_id = ? AND activo = 1 ORDER BY predeterminada DESC, id DESC LIMIT 1');
        $tarjeta->execute([$usuario['id']]);
        if (!$tarjeta->fetch()) throw new \RuntimeException('Agrega una tarjeta de prueba antes de recargar el monedero.');
        $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
            ->execute([$monto, $usuario['id']]);
        $pdo->commit();

        jsonResponse([
            'success' => true,
            'mensaje' => "Recarga de $$monto MXN acreditada a tu monedero.",
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}
