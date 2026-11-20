<?php
/**
 * api/citas.php
 * -------------------------------------------------
 * Endpoint REST para gestión de agenda de citas:
 * - Detección de disponibilidad / slots
 * - Máquina de estados con anticipos en custodia (ESCROW)
 * - Confirmación mutua con códigos temporales de 6 dígitos
 * - Auditoría en movimientos_ledger y política de cancelación
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

// Aplicar automáticamente la tolerancia de 15 minutos antes de cada operación.
procesarNoShowsVencidos();
procesarLiberacionesPendientes();

match ($action) {
    'slots'     => handleSlots($method),
    'crear'     => handleCrearCita($method),
    'listar'    => handleListarCitas($method),
    'confirmar' => handleConfirmarCita($method),
    'cancelar'  => handleCancelarCita($method),
    'generar_checkin' => handleGenerarCheckin($method),
    'validar_checkin' => handleValidarCheckin($method),
    'solicitar_prorroga' => handleSolicitarProrroga($method),
    'responder_prorroga' => handleResponderProrroga($method),
    'generar_final' => handleGenerarFinal($method),
    'confirmar_final' => handleConfirmarFinal($method),
    'resolver_no_show' => handleResolverNoShow($method),
    'abrir_disputa' => handleAbrirDisputa($method),
    'resolver_disputa' => handleResolverDisputa($method),
    default     => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida en citas.php.",
    ], 400),
};

/**
 * Marca citas vencidas como no_show y libera el anticipo al negocio.
 * El bloqueo de filas evita acreditar dos veces ante peticiones simultáneas.
 */
function procesarNoShowsVencidos(): void
{
    $pdo = getPDO();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM citas c
            JOIN negocios n ON n.id = c.negocio_id
            LEFT JOIN citas_control cc ON cc.cita_id = c.id
            WHERE c.estado IN ('agendada', 'confirmada')
              AND DATE_ADD(
                    c.fecha_hora_inicio,
                    INTERVAL (15 + IF(cc.prorroga_estado = 'aceptada', cc.prorroga_minutos, 0)) MINUTE
                  ) < NOW()
            ORDER BY c.fecha_hora_inicio ASC
            LIMIT 100
            FOR UPDATE
        ");
        $stmt->execute();
        $vencidas = $stmt->fetchAll();

        $actualizar = $pdo->prepare("
            UPDATE citas
            SET estado = 'no_show'
            WHERE id = ? AND estado IN ('agendada', 'confirmada')
        ");
        $actualizarControl = $pdo->prepare("UPDATE citas_control SET estado_flujo = 'no_show_pendiente' WHERE cita_id = ?");

        foreach ($vencidas as $cita) {
            $actualizar->execute([$cita['id']]);
            if ($actualizar->rowCount() !== 1) continue;
            $actualizarControl->execute([$cita['id']]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PROCESAR_NO_SHOW_ERROR] ' . $e->getMessage());
    }
}

/**
 * GET /api/citas.php?action=slots&negocio_id=1&servicio_id=1&fecha=2026-11-20
 * Devuelve los intervalos de horario disponibles considerando la duración del servicio.
 */
function handleSlots(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $negocioId  = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $servicioId = filter_var($_GET['servicio_id'] ?? null, FILTER_VALIDATE_INT);
    $fecha      = trim($_GET['fecha'] ?? '');

    if (!$negocioId || !$servicioId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        jsonResponse(['success' => false, 'error' => 'negocio_id, servicio_id y fecha (YYYY-MM-DD) son obligatorios.'], 422);
    }

    $pdo = getPDO();

    // 1. Obtener duración del servicio
    $stmtS = $pdo->prepare('SELECT duracion_minutos FROM servicios WHERE id = ? AND negocio_id = ? AND activo = 1 LIMIT 1');
    $stmtS->execute([$servicioId, $negocioId]);
    $duracion = (int) $stmtS->fetchColumn();

    if (!$duracion) {
        jsonResponse(['success' => false, 'error' => 'Servicio no encontrado o inactivo.'], 404);
    }

    // 2. Obtener citas ocupadas en esa fecha
    $stmtC = $pdo->prepare("
        SELECT fecha_hora_inicio, fecha_hora_fin
        FROM citas
        WHERE negocio_id = ?
          AND DATE(fecha_hora_inicio) = ?
          AND estado IN ('pendiente_pago', 'agendada', 'confirmada', 'en_curso')
    ");
    $stmtC->execute([$negocioId, $fecha]);
    $citasOcupadas = $stmtC->fetchAll();

    // 3. Generar franjas horarias de operación estándar (09:00 a 19:00) en intervalos de 30 o duración
    $intervaloMinutos = 30;
    $horaApertura     = 9;  // 09:00
    $horaCierre       = 19; // 19:00

    $slots = [];
    $inicioDia = new DateTimeImmutable("$fecha $horaApertura:00:00");
    $finDia    = new DateTimeImmutable("$fecha $horaCierre:00:00");

    $slotActual = $inicioDia;

    while ($slotActual < $finDia) {
        $slotFin = $slotActual->modify("+{$duracion} minutes");

        // El servicio debe terminar antes o a la hora del cierre
        if ($slotFin > $finDia) {
            break;
        }

        // Validar si traslapa con alguna cita existente
        $disponible = true;
        foreach ($citasOcupadas as $cita) {
            $citaIni = new DateTimeImmutable($cita['fecha_hora_inicio']);
            $citaFin = new DateTimeImmutable($cita['fecha_hora_fin']);

            // Condición de traslape: max(inicio1, inicio2) < min(fin1, fin2)
            if ($slotActual < $citaFin && $slotFin > $citaIni) {
                $disponible = false;
                break;
            }
        }

        // Si es hoy, verificar que la hora sea futura (+15 min de margen)
        $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
        if ($slotActual <= $ahora->modify('+15 minutes')) {
            $disponible = false;
        }

        $slots[] = [
            'hora_inicio' => $slotActual->format('H:i'),
            'hora_fin'    => $slotFin->format('H:i'),
            'disponible'  => $disponible,
        ];

        $slotActual = $slotActual->modify("+{$intervaloMinutos} minutes");
    }

    jsonResponse(['success' => true, 'slots' => $slots]);
}

/**
 * POST /api/citas.php?action=crear
 * Crea una cita y retiene el anticipo en custodia (Escrow).
 */
function handleCrearCita(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('cliente');
    $body    = getJsonInput();
    $pdo     = getPDO();

    $negocioId  = filter_var($body['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $servicioId = filter_var($body['servicio_id'] ?? null, FILTER_VALIDATE_INT);
    $fechaInicio = trim($body['fecha_hora_inicio'] ?? '');
    $modalidadPago = trim($body['modalidad_pago'] ?? 'anticipo');

    if (!$negocioId || !$servicioId || !$fechaInicio || !in_array($modalidadPago, ['sin_anticipo','anticipo','total'], true)) {
        jsonResponse(['success' => false, 'error' => 'Datos de cita incompletos.'], 422);
    }

    try {
        $pdo->beginTransaction();

        // 1. Obtener datos del servicio y negocio
        $stmt = $pdo->prepare('
            SELECT s.nombre, s.duracion_minutos, s.monto_anticipo, s.precio_estimado,
                   n.usuario_id AS dueno_id, n.plan_suscripcion,
                   COALESCE(cp.permite_anticipo, 1) AS permite_anticipo,
                   COALESCE(cp.permite_pago_total, 1) AS permite_pago_total
            FROM servicios s
            JOIN negocios n ON n.id = s.negocio_id
            LEFT JOIN servicios_config_pago cp ON cp.servicio_id = s.id
            WHERE s.id = ? AND s.negocio_id = ? AND s.activo = 1 AND n.activo = 1
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$servicioId, $negocioId]);
        $info = $stmt->fetch();

        if (!$info) {
            throw new \RuntimeException('El servicio o negocio seleccionado no está disponible.');
        }

        $duracion = (int) $info['duracion_minutos'];
        $anticipo = (float) $info['monto_anticipo'];
        $precioTotal = (float) $info['precio_estimado'];
        if ($modalidadPago === 'total' && !(bool) $info['permite_pago_total']) throw new RuntimeException('El pago total no está habilitado para este servicio.');
        if (in_array($modalidadPago, ['anticipo','sin_anticipo'], true) && !(bool) $info['permite_anticipo']) throw new RuntimeException('La reservación con garantía no está habilitada para este servicio.');
        if ($modalidadPago === 'sin_anticipo' && $anticipo > 0) throw new RuntimeException('Este servicio requiere una garantía para reservar.');
        if ($modalidadPago === 'anticipo' && $anticipo <= 0) $modalidadPago = 'sin_anticipo';
        $montoPagado = $modalidadPago === 'total' ? $precioTotal : ($modalidadPago === 'anticipo' ? $anticipo : 0.0);

        $tarjeta = $pdo->prepare('SELECT id FROM metodos_pago WHERE usuario_id = ? AND activo = 1 ORDER BY predeterminada DESC, id DESC LIMIT 1');
        $tarjeta->execute([$usuario['id']]);
        if (!$tarjeta->fetch()) throw new RuntimeException('Agrega una tarjeta de prueba para respaldar la reservación y el cierre digital del servicio.');

        if ($info['plan_suscripcion'] === 'basico' && $anticipo > 0) {
            $stmtLimite = $pdo->prepare("
                SELECT COUNT(*)
                FROM citas
                WHERE negocio_id = ?
                  AND monto_anticipo > 0
                  AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
                  AND created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)
            ");
            $stmtLimite->execute([$negocioId]);
            if ((int) $stmtLimite->fetchColumn() >= 5) {
                throw new \RuntimeException('Este negocio alcanzó el límite mensual de 5 citas con anticipo de su Plan Básico.');
            }
        }

        $dtInicio = new DateTimeImmutable($fechaInicio);
        if ($dtInicio <= new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'))) throw new RuntimeException('La cita debe programarse en una fecha futura.');
        $dtFin    = $dtInicio->modify("+{$duracion} minutes");
        $inicioStr = $dtInicio->format('Y-m-d H:i:s');
        $finStr    = $dtFin->format('Y-m-d H:i:s');

        // 2. Verificar traslapes (bloqueando filas con FOR UPDATE)
        $stmtT = $pdo->prepare("
            SELECT id FROM citas
            WHERE negocio_id = ?
              AND estado IN ('pendiente_pago', 'agendada', 'confirmada', 'en_curso')
              AND (fecha_hora_inicio < ? AND fecha_hora_fin > ?)
            FOR UPDATE
        ");
        $stmtT->execute([$negocioId, $finStr, $inicioStr]);
        if ($stmtT->fetch()) {
            throw new \RuntimeException('El horario seleccionado ya ha sido reservado por otro cliente. Elige otro.');
        }

        // 3. Valor legado requerido por el esquema original. El flujo público usa códigos temporales de 6 dígitos.
        $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // 4. Crear la cita en estado 'agendada'
        $insertCita = $pdo->prepare("
            INSERT INTO citas (
                servicio_id, negocio_id, cliente_id,
                fecha_hora_inicio, fecha_hora_fin,
                estado, monto_anticipo, monto_anticipo_retenido,
                pin_verificacion
            ) VALUES (?, ?, ?, ?, ?, 'agendada', ?, ?, ?)
        ");
        $insertCita->execute([
            $servicioId,
            $negocioId,
            $usuario['id'],
            $inicioStr,
            $finStr,
            $anticipo,
            $anticipo,
            $pin,
        ]);
        $citaId = (int) $pdo->lastInsertId();

        $comision = $info['plan_suscripcion'] === 'basico' ? 1.00 : 0.00;
        $pdo->prepare('INSERT INTO citas_control (cita_id, modalidad_pago, precio_total, garantia, monto_pagado, comision_porcentaje, estado_flujo) VALUES (?, ?, ?, ?, ?, ?, \'reservada\')')
            ->execute([$citaId, $modalidadPago, $precioTotal, $anticipo, $montoPagado, $comision]);

        // 5. Registrar movimiento en el libro mayor (movimientos_ledger)
        if ($montoPagado > 0) {
            $insertLedger = $pdo->prepare("
                INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                VALUES (?, ?, 'retencion_anticipo', ?, ?)
            ");
            $insertLedger->execute([
                $citaId,
                $usuario['id'],
                $montoPagado,
                "Pago digital simulado retenido para cita #$citaId de " . $info['nombre'],
            ]);
        }

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'mensaje' => '¡Cita agendada exitosamente!',
            'cita'    => [
                'id'                => $citaId,
                'fecha_hora_inicio' => $inicioStr,
                'fecha_hora_fin'    => $finStr,
                'monto_anticipo'    => $anticipo,
                'monto_pagado'      => $montoPagado,
                'modalidad_pago'    => $modalidadPago,
                'estado'            => 'agendada',
            ],
        ], 201);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/**
 * GET /api/citas.php?action=listar&negocio_id=1&estado=...
 * Lista citas para el panel de administración o perfil de cliente.
 */
function handleListarCitas(string $method): never
{
    if ($method !== 'GET') jsonResponse(['success' => false, 'error' => 'Usa GET.'], 405);

    $usuario   = requireAuth();
    $negocioId = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    $estado    = trim($_GET['estado'] ?? '');
    $pdo       = getPDO();

    $where = [];
    $params = [];

    if ($usuario['rol'] === 'negocio') {
        // Verificar que el negocio le pertenezca
        $stmtN = $pdo->prepare('SELECT id FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1');
        $stmtN->execute([$negocioId, $usuario['id']]);
        if (!$stmtN->fetch()) jsonResponse(['success' => false, 'error' => 'No autorizado.'], 403);

        $where[] = 'c.negocio_id = :negocio_id';
        $params[':negocio_id'] = $negocioId;
    } else {
        // Cliente consulta sus propias citas
        $where[] = 'c.cliente_id = :cliente_id';
        $params[':cliente_id'] = $usuario['id'];
    }

    if ($estado !== '') {
        $where[] = 'c.estado = :estado';
        $params[':estado'] = $estado;
    }

    $whereSQL = implode(' AND ', $where);

    $sql = "
        SELECT 
            c.id, c.servicio_id, c.negocio_id, c.cliente_id,
            c.fecha_hora_inicio, c.fecha_hora_fin, c.estado,
            c.monto_anticipo, c.created_at,
            s.nombre AS nombre_servicio,
            s.duracion_minutos,
            u.nombre AS nombre_cliente,
            u.telefono AS telefono_cliente,
            n.nombre AS nombre_negocio,
            n.ventana_cancelacion_horas,
            cc.modalidad_pago, cc.precio_total, cc.garantia, cc.monto_pagado,
            cc.estado_flujo,
            cc.cliente_validado_at, cc.negocio_validado_at,
            cc.prorroga_minutos, cc.prorroga_estado, cc.prorroga_solicitada_por,
            (cc.final_codigo IS NOT NULL) AS final_pendiente,
            cc.final_respuesta_limite, cc.liberar_at,
            d.id AS disputa_id, d.estado AS disputa_estado
        FROM citas c
        JOIN servicios s ON s.id = c.servicio_id
        JOIN usuarios u ON u.id = c.cliente_id
        JOIN negocios n ON n.id = c.negocio_id
        LEFT JOIN citas_control cc ON cc.cita_id = c.id
        LEFT JOIN citas_disputas d ON d.cita_id = c.id AND d.estado IN ('abierta','en_revision')
        WHERE $whereSQL
        ORDER BY c.fecha_hora_inicio ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $citas = $stmt->fetchAll();

    jsonResponse(['success' => true, 'citas' => $citas]);
}

/**
 * POST /api/citas.php?action=confirmar
 * Negocio confirma explícitamente una cita en estado 'agendada'.
 */
function handleConfirmarCita(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $citaId  = filter_var($body['cita_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$citaId) jsonResponse(['success' => false, 'error' => 'cita_id inválido.'], 422);

    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        UPDATE citas c
        JOIN negocios n ON n.id = c.negocio_id
        SET c.estado = 'confirmada'
        WHERE c.id = ? AND n.usuario_id = ? AND c.estado = 'agendada'
    ");
    $stmt->execute([$citaId, $usuario['id']]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['success' => false, 'error' => 'No se pudo confirmar la cita (no está en estado agendada o sin permisos).'], 400);
    }

    jsonResponse(['success' => true, 'mensaje' => 'Cita confirmada correctamente.']);
}

/**
 * POST /api/citas.php?action=cancelar
 * Procesa la cancelación con la política vigente:
 * - Antes de la hora exacta: reembolso completo.
 * - Desde la hora exacta: ausencia pendiente de resolución por el negocio.
 */
function handleCancelarCita(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth();
    $body    = getJsonInput();
    $citaId  = filter_var($body['cita_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$citaId) jsonResponse(['success' => false, 'error' => 'cita_id inválido.'], 422);

    $pdo = getPDO();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT c.id, c.cliente_id, c.fecha_hora_inicio, c.monto_anticipo, c.estado,
                   n.usuario_id AS dueno_negocio_id, cc.monto_pagado
            FROM citas c
            JOIN negocios n ON n.id = c.negocio_id
            JOIN citas_control cc ON cc.cita_id = c.id
            WHERE c.id = ?
              AND c.estado IN ('agendada', 'confirmada')
              AND (c.cliente_id = ? OR n.usuario_id = ?)
            FOR UPDATE
        ");
        $stmt->execute([$citaId, $usuario['id'], $usuario['id']]);
        $cita = $stmt->fetch();

        if (!$cita) {
            throw new \RuntimeException('La cita no existe o ya no puede ser cancelada.');
        }

        $ahora       = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
        $fechaInicio = new DateTimeImmutable($cita['fecha_hora_inicio']);
        $montoPagado = (float) $cita['monto_pagado'];
        $esDueno = $usuario['id'] === (int) $cita['dueno_negocio_id'];
        $esATiempo = $ahora < $fechaInicio || $esDueno;

        if ($esATiempo) {
            // Cancelada a tiempo -> 100% reembolsado al monedero del cliente
            $nuevoEstado = 'cancelada_tiempo';

            $pdo->prepare("UPDATE citas SET estado = ?, monto_anticipo_retenido = 0 WHERE id = ?")
                ->execute([$nuevoEstado, $citaId]);

            if ($montoPagado > 0) {
                $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
                    ->execute([$montoPagado, $cita['cliente_id']]);

                $pdo->prepare("
                    INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                    VALUES (?, ?, 'reembolso_cliente', ?, ?)
                ")->execute([
                    $citaId,
                    $cita['cliente_id'],
                    $montoPagado,
                    "Reembolso completo por cancelación antes de la hora de cita #$citaId",
                ]);
            }

            $pdo->prepare("UPDATE citas_control SET estado_flujo='cancelada', liberado_at=NOW() WHERE cita_id=?")->execute([$citaId]);
            $mensaje = $esDueno ? 'El negocio canceló la cita. Se reembolsó el pago completo al cliente.' : 'Cita cancelada antes de la hora programada. Recibirás el reembolso completo.';

        } else {
            $nuevoEstado = 'no_show';
            $pdo->prepare("UPDATE citas SET estado='no_show' WHERE id=?")->execute([$citaId]);
            $pdo->prepare("UPDATE citas_control SET estado_flujo='no_show_pendiente' WHERE cita_id=?")->execute([$citaId]);
            $mensaje = 'La hora de cancelación gratuita terminó. Se registró la ausencia y el negocio decidirá si perdona o retiene parte de la garantía.';
        }

        $pdo->commit();

        jsonResponse([
            'success'      => true,
            'nuevo_estado' => $nuevoEstado,
            'mensaje'      => $mensaje,
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

function procesarLiberacionesPendientes(): void
{
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $vencidas = $pdo->query("
            SELECT cc.cita_id, cc.monto_pagado, cc.precio_total, c.cliente_id
            FROM citas_control cc
            JOIN citas c ON c.id=cc.cita_id
            LEFT JOIN citas_disputas d ON d.cita_id=c.id AND d.estado IN ('abierta','en_revision')
            WHERE cc.estado_flujo='finalizacion_pendiente'
              AND cc.final_respuesta_limite IS NOT NULL
              AND cc.final_respuesta_limite <= NOW()
              AND d.id IS NULL
            FOR UPDATE
        ")->fetchAll();
        foreach ($vencidas as $vencida) {
            $saldo = round(max(0, (float) $vencida['precio_total'] - (float) $vencida['monto_pagado']), 2);
            if ($saldo > 0) {
                $pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion,referencia_pago) VALUES(?,?,'retencion_anticipo',?,?,?)")
                    ->execute([$vencida['cita_id'], $vencida['cliente_id'], $saldo, "Saldo automático al vencer la confirmación de cita #{$vencida['cita_id']}", 'SIM-AUTO-' . strtoupper(bin2hex(random_bytes(4)))]);
            }
            $pdo->prepare("UPDATE citas_control SET monto_pagado=precio_total, estado_flujo='retencion_24h', liberar_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE cita_id=?")
                ->execute([$vencida['cita_id']]);
        }
        $stmt = $pdo->query("
            SELECT cc.cita_id, cc.monto_pagado, cc.comision_porcentaje, n.usuario_id AS dueno_id
            FROM citas_control cc
            JOIN citas c ON c.id=cc.cita_id JOIN negocios n ON n.id=c.negocio_id
            LEFT JOIN citas_disputas d ON d.cita_id=c.id AND d.estado IN ('abierta','en_revision')
            WHERE cc.estado_flujo='retencion_24h' AND cc.liberar_at <= NOW() AND cc.liberado_at IS NULL AND d.id IS NULL
            FOR UPDATE
        ");
        foreach ($stmt->fetchAll() as $fila) {
            $bruto = (float) $fila['monto_pagado'];
            $neto = round($bruto * (1 - (float) $fila['comision_porcentaje'] / 100), 2);
            if ($neto > 0) $pdo->prepare('UPDATE usuarios SET saldo_monedero=saldo_monedero+? WHERE id=?')->execute([$neto, $fila['dueno_id']]);
            $pdo->prepare("UPDATE citas SET estado='completada', monto_anticipo_retenido=monto_anticipo WHERE id=?")->execute([$fila['cita_id']]);
            $pdo->prepare("UPDATE citas_control SET estado_flujo='liberada', liberado_at=NOW() WHERE cita_id=?")->execute([$fila['cita_id']]);
            if ($neto > 0) $pdo->prepare("INSERT INTO movimientos_ledger (cita_id,usuario_id,tipo,monto,descripcion) VALUES (?,?,'liberacion_negocio',?,?)")
                ->execute([$fila['cita_id'], $fila['dueno_id'], $neto, "Pago liberado después de 24 horas para cita #{$fila['cita_id']}"]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[LIBERACIONES_ERROR] ' . $e->getMessage());
    }
}

function obtenerCitaDeParte(PDO $pdo, int $citaId, array $usuario, bool $bloquear = false): array
{
    $sql = "SELECT c.*, n.usuario_id AS dueno_id, cc.* FROM citas c JOIN negocios n ON n.id=c.negocio_id JOIN citas_control cc ON cc.cita_id=c.id WHERE c.id=? AND (c.cliente_id=? OR n.usuario_id=?)" . ($bloquear ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$citaId, $usuario['id'], $usuario['id']]);
    $cita = $stmt->fetch();
    if (!$cita) jsonResponse(['success'=>false,'error'=>'Cita no encontrada o sin permiso.'], 404);
    return $cita;
}

function handleGenerarCheckin(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth(); $id=filter_var(getJsonInput()['cita_id']??null,FILTER_VALIDATE_INT); if(!$id) jsonResponse(['success'=>false,'error'=>'Cita inválida.'],422);
    $pdo=getPDO(); $cita=obtenerCitaDeParte($pdo,$id,$usuario);
    if(!in_array($cita['estado'],['agendada','confirmada'],true)) jsonResponse(['success'=>false,'error'=>'La cita no permite check-in.'],409);
    $codigo=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $campo=$usuario['id']==(int)$cita['cliente_id']?'cliente_codigo':'negocio_codigo';
    $pdo->prepare("UPDATE citas_control SET $campo=?, estado_flujo='checkin_pendiente' WHERE cita_id=?")->execute([$codigo,$id]);
    jsonResponse(['success'=>true,'codigo'=>$codigo,'mensaje'=>'Código temporal generado. La otra parte debe validarlo.']);
}

function handleValidarCheckin(string $method): never
{
    if($method!=='POST') jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth(); $body=getJsonInput(); $id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT); $codigo=trim((string)($body['codigo']??''));
    if(!$id||!preg_match('/^\d{6}$/',$codigo)) jsonResponse(['success'=>false,'error'=>'Código de 6 dígitos inválido.'],422);
    $pdo=getPDO(); $pdo->beginTransaction();
    try {
        $cita=obtenerCitaDeParte($pdo,$id,$usuario,true);
        $esCliente=$usuario['id']==(int)$cita['cliente_id'];
        $esperado=$esCliente?$cita['negocio_codigo']:$cita['cliente_codigo'];
        if(!$esperado||!hash_equals($esperado,$codigo)) throw new RuntimeException('El código no coincide o todavía no fue generado.');
        $campo=$esCliente?'negocio_validado_at':'cliente_validado_at';
        $pdo->prepare("UPDATE citas_control SET $campo=NOW() WHERE cita_id=?")->execute([$id]);
        $estado=$pdo->prepare('SELECT cliente_validado_at,negocio_validado_at FROM citas_control WHERE cita_id=?'); $estado->execute([$id]); $v=$estado->fetch();
        $completo=$v['cliente_validado_at']&&$v['negocio_validado_at'];
        if($completo){$pdo->prepare("UPDATE citas SET estado='en_curso' WHERE id=?")->execute([$id]);$pdo->prepare("UPDATE citas_control SET estado_flujo='en_curso' WHERE cita_id=?")->execute([$id]);}
        $pdo->commit(); jsonResponse(['success'=>true,'checkin_completo'=>(bool)$completo,'mensaje'=>$completo?'Check-in mutuo completado. El servicio está en curso.':'Código validado. Falta la confirmación de la otra parte.']);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(['success'=>false,'error'=>$e->getMessage()],400);}
}

function handleSolicitarProrroga(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth();$body=getJsonInput();$id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT);$min=(int)($body['minutos']??0);
    if(!$id||!in_array($min,[10,15,30],true))jsonResponse(['success'=>false,'error'=>'Selecciona 10, 15 o 30 minutos.'],422);
    $pdo=getPDO();$cita=obtenerCitaDeParte($pdo,$id,$usuario);
    if(!in_array($cita['estado'],['agendada','confirmada'],true)||new DateTimeImmutable()>new DateTimeImmutable($cita['fecha_hora_inicio'].' +15 minutes'))jsonResponse(['success'=>false,'error'=>'La prórroga ya no puede solicitarse.'],409);
    $pdo->prepare("UPDATE citas_control SET prorroga_minutos=?,prorroga_estado='solicitada',prorroga_solicitada_por=? WHERE cita_id=? AND prorroga_estado IN ('ninguna','rechazada')")->execute([$min,$usuario['id'],$id]);
    jsonResponse(['success'=>true,'mensaje'=>'Prórroga solicitada. La otra parte debe aceptarla.']);
}

function handleResponderProrroga(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth();$body=getJsonInput();$id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT);$aceptar=filter_var($body['aceptar']??false,FILTER_VALIDATE_BOOLEAN);
    $pdo=getPDO();$cita=obtenerCitaDeParte($pdo,$id,$usuario);
    if($cita['prorroga_estado']!=='solicitada'||(int)$cita['prorroga_solicitada_por']===$usuario['id'])jsonResponse(['success'=>false,'error'=>'No existe una prórroga pendiente para responder.'],409);
    $pdo->prepare('UPDATE citas_control SET prorroga_estado=? WHERE cita_id=?')->execute([$aceptar?'aceptada':'rechazada',$id]);
    jsonResponse(['success'=>true,'mensaje'=>$aceptar?'Prórroga aceptada.':'Prórroga rechazada.']);
}

function handleGenerarFinal(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth('negocio');$id=filter_var(getJsonInput()['cita_id']??null,FILTER_VALIDATE_INT);$pdo=getPDO();$cita=obtenerCitaDeParte($pdo,$id,$usuario);
    if((int)$cita['dueno_id']!==$usuario['id']||$cita['estado']!=='en_curso')jsonResponse(['success'=>false,'error'=>'Solo puedes finalizar una cita en curso de tu negocio.'],409);
    $codigo=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $pdo->prepare("UPDATE citas_control SET final_codigo=?,final_solicitada_at=NOW(),final_respuesta_limite=DATE_ADD(NOW(),INTERVAL 2 HOUR),estado_flujo='finalizacion_pendiente' WHERE cita_id=?")->execute([$codigo,$id]);
    jsonResponse(['success'=>true,'codigo'=>$codigo,'mensaje'=>'Código final generado. El cliente tiene 2 horas para confirmarlo o disputar.']);
}

function handleConfirmarFinal(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth('cliente');$body=getJsonInput();$id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT);$codigo=trim((string)($body['codigo']??''));
    $pdo=getPDO();$cita=obtenerCitaDeParte($pdo,$id,$usuario);
    if((int)$cita['cliente_id']!==$usuario['id']||!$cita['final_codigo']||!hash_equals($cita['final_codigo'],$codigo))jsonResponse(['success'=>false,'error'=>'Código final inválido.'],403);
    if((float)$cita['monto_pagado']<(float)$cita['precio_total']){
        $tarjeta=$pdo->prepare('SELECT id FROM metodos_pago WHERE usuario_id=? AND activo=1 LIMIT 1');$tarjeta->execute([$usuario['id']]);if(!$tarjeta->fetch())jsonResponse(['success'=>false,'error'=>'Agrega una tarjeta para pagar el saldo restante.'],409);
    }
    $saldo=round(max(0,(float)$cita['precio_total']-(float)$cita['monto_pagado']),2);
    try{
        $pdo->beginTransaction();
        $cita=obtenerCitaDeParte($pdo,$id,$usuario,true);
        $pdo->prepare("UPDATE citas_control SET monto_pagado=precio_total,final_confirmada_at=NOW(),liberar_at=DATE_ADD(NOW(),INTERVAL 24 HOUR),estado_flujo='retencion_24h' WHERE cita_id=?")->execute([$id]);
        if($saldo>0)$pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion,referencia_pago)VALUES(?,?,'retencion_anticipo',?,?,?)")
            ->execute([$id,$usuario['id'],$saldo,"Saldo del servicio retenido para cita #$id",'SIM-SVC-'.strtoupper(bin2hex(random_bytes(4)))]);
        $pdo->commit();
        jsonResponse(['success'=>true,'mensaje'=>'Servicio confirmado. El pago quedará retenido 24 horas para posibles disputas.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(['success'=>false,'error'=>'No fue posible confirmar el pago final.'],500);}
}

function handleAbrirDisputa(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth();$body=getJsonInput();$id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT);$motivo=trim($body['motivo']??'otro');$desc=trim($body['descripcion']??'');
    if(!$id||mb_strlen($desc)<10||mb_strlen($desc)>2000)jsonResponse(['success'=>false,'error'=>'Describe el problema con al menos 10 caracteres.'],422);
    $pdo=getPDO();obtenerCitaDeParte($pdo,$id,$usuario);
    $resolverHasta=agregarDiasHabiles(new DateTimeImmutable('now'),7)->format('Y-m-d H:i:s');
    try{$pdo->prepare("INSERT INTO citas_disputas(cita_id,abierta_por,motivo,descripcion,evidencias_hasta,resolver_hasta) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 48 HOUR),?)")->execute([$id,$usuario['id'],$motivo,$desc,$resolverHasta]);$pdo->prepare("UPDATE citas_control SET estado_flujo='en_disputa' WHERE cita_id=?")->execute([$id]);}
    catch(Throwable $e){jsonResponse(['success'=>false,'error'=>'Ya existe una disputa activa para esta cita.'],409);}
    jsonResponse(['success'=>true,'mensaje'=>'Disputa abierta. Hay 48 horas para aportar evidencias y el pago permanecerá retenido.'],201);
}

function agregarDiasHabiles(DateTimeImmutable $fecha, int $dias): DateTimeImmutable
{
    while ($dias > 0) {
        $fecha = $fecha->modify('+1 day');
        if ((int) $fecha->format('N') <= 5) $dias--;
    }
    return $fecha;
}

function handleResolverDisputa(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $admin=requireAuth('admin');$body=getJsonInput();$disputaId=filter_var($body['disputa_id']??null,FILTER_VALIDATE_INT);$reembolso=round((float)($body['monto_reembolso']??-1),2);
    if(!$disputaId||$reembolso<0)jsonResponse(['success'=>false,'error'=>'Datos de resolución inválidos.'],422);
    $pdo=getPDO();$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT d.*,c.cliente_id,n.usuario_id AS dueno_id,cc.monto_pagado,cc.comision_porcentaje FROM citas_disputas d JOIN citas c ON c.id=d.cita_id JOIN negocios n ON n.id=c.negocio_id JOIN citas_control cc ON cc.cita_id=c.id WHERE d.id=? FOR UPDATE");
        $stmt->execute([$disputaId]);$d=$stmt->fetch();
        if(!$d||!in_array($d['estado'],['abierta','en_revision'],true))throw new RuntimeException('La disputa no está disponible para resolución.');
        $pagado=(float)$d['monto_pagado'];if($reembolso>$pagado)throw new RuntimeException('El reembolso no puede superar el monto retenido.');
        $brutoNegocio=round($pagado-$reembolso,2);$netoNegocio=round($brutoNegocio*(1-(float)$d['comision_porcentaje']/100),2);
        if($reembolso>0){$pdo->prepare('UPDATE usuarios SET saldo_monedero=saldo_monedero+? WHERE id=?')->execute([$reembolso,$d['cliente_id']]);$pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion)VALUES(?,?,'reembolso_cliente',?,?)")->execute([$d['cita_id'],$d['cliente_id'],$reembolso,"Resolución de disputa #$disputaId"]);}
        if($netoNegocio>0){$pdo->prepare('UPDATE usuarios SET saldo_monedero=saldo_monedero+? WHERE id=?')->execute([$netoNegocio,$d['dueno_id']]);$pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion)VALUES(?,?,'liberacion_negocio',?,?)")->execute([$d['cita_id'],$d['dueno_id'],$netoNegocio,"Resolución de disputa #$disputaId"]);}
        $resolucion=$reembolso<=0?'pago_negocio':($reembolso>=$pagado?'reembolso_total':($netoNegocio>0?'dividido':'reembolso_parcial'));
        $pdo->prepare("UPDATE citas_disputas SET estado='resuelta',resolucion=?,monto_reembolso=?,resuelta_at=NOW() WHERE id=?")->execute([$resolucion,$reembolso,$disputaId]);
        $pdo->prepare("UPDATE citas_control SET estado_flujo='liberada',liberado_at=NOW() WHERE cita_id=?")->execute([$d['cita_id']]);
        $pdo->prepare("UPDATE citas SET estado='completada' WHERE id=?")->execute([$d['cita_id']]);$pdo->commit();
        jsonResponse(['success'=>true,'mensaje'=>'Disputa resuelta y fondos distribuidos.','monto_reembolso'=>$reembolso,'monto_negocio'=>$netoNegocio,'resuelta_por'=>$admin['id']]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(['success'=>false,'error'=>$e->getMessage()],400);}
}

function handleResolverNoShow(string $method): never
{
    if($method!=='POST')jsonResponse(['success'=>false,'error'=>'Usa POST.'],405);
    $usuario=requireAuth('negocio');$body=getJsonInput();$id=filter_var($body['cita_id']??null,FILTER_VALIDATE_INT);$porcentaje=(int)($body['porcentaje']??-1);
    if(!$id||!in_array($porcentaje,[0,25,50,75,100],true))jsonResponse(['success'=>false,'error'=>'Porcentaje inválido.'],422);
    $pdo=getPDO();$pdo->beginTransaction();
    try{$cita=obtenerCitaDeParte($pdo,$id,$usuario,true);if((int)$cita['dueno_id']!==$usuario['id']||$cita['estado']!=='no_show')throw new RuntimeException('La cita no está pendiente de resolución por ausencia.');
        $retener=round(min((float)$cita['garantia'],(float)$cita['monto_pagado'])*$porcentaje/100,2);$reembolso=round((float)$cita['monto_pagado']-$retener,2);$neto=round($retener*(1-(float)$cita['comision_porcentaje']/100),2);
        if($reembolso>0){$pdo->prepare('UPDATE usuarios SET saldo_monedero=saldo_monedero+? WHERE id=?')->execute([$reembolso,$cita['cliente_id']]);$pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion)VALUES(?,?,'reembolso_cliente',?,?)")->execute([$id,$cita['cliente_id'],$reembolso,"Reembolso por resolución de ausencia en cita #$id"]);}
        if($neto>0){$pdo->prepare('UPDATE usuarios SET saldo_monedero=saldo_monedero+? WHERE id=?')->execute([$neto,$cita['dueno_id']]);$pdo->prepare("INSERT INTO movimientos_ledger(cita_id,usuario_id,tipo,monto,descripcion)VALUES(?,?,'compensacion_negocio',?,?)")->execute([$id,$cita['dueno_id'],$neto,"Compensación de $porcentaje% de garantía por cita #$id"]);}
        $pdo->prepare("UPDATE citas SET monto_anticipo_retenido=? WHERE id=?")->execute([$retener,$id]);$pdo->prepare("UPDATE citas_control SET estado_flujo='no_show_resuelto',liberado_at=NOW() WHERE cita_id=?")->execute([$id]);$pdo->commit();
        jsonResponse(['success'=>true,'mensaje'=>'Ausencia resuelta.','retenido'=>$retener,'reembolsado'=>$reembolso]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(['success'=>false,'error'=>$e->getMessage()],400);}
}
