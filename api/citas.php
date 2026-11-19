<?php
/**
 * api/citas.php
 * -------------------------------------------------
 * Endpoint REST para gestión de agenda de citas:
 * - Detección de disponibilidad / slots
 * - Máquina de estados con anticipos en custodia (ESCROW)
 * - Validación por PIN de 4 dígitos en el local
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

match ($action) {
    'slots'     => handleSlots($method),
    'crear'     => handleCrearCita($method),
    'listar'    => handleListarCitas($method),
    'confirmar' => handleConfirmarCita($method),
    'iniciar'   => handleIniciarCitaConPin($method),
    'completar' => handleCompletarCita($method),
    'cancelar'  => handleCancelarCita($method),
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
            SELECT c.id, c.monto_anticipo, n.usuario_id AS negocio_usuario_id
            FROM citas c
            JOIN negocios n ON n.id = c.negocio_id
            WHERE c.estado IN ('agendada', 'confirmada')
              AND c.fecha_hora_inicio < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ORDER BY c.fecha_hora_inicio ASC
            LIMIT 100
            FOR UPDATE
        ");
        $stmt->execute();
        $vencidas = $stmt->fetchAll();

        $actualizar = $pdo->prepare("
            UPDATE citas
            SET estado = 'no_show', monto_anticipo_retenido = monto_anticipo
            WHERE id = ? AND estado IN ('agendada', 'confirmada')
        ");
        $acreditar = $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?');
        $ledger = $pdo->prepare("
            INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
            VALUES (?, ?, 'compensacion_negocio', ?, ?)
        ");

        foreach ($vencidas as $cita) {
            $actualizar->execute([$cita['id']]);
            if ($actualizar->rowCount() !== 1) continue;

            $anticipo = (float) $cita['monto_anticipo'];
            if ($anticipo <= 0) continue;

            $acreditar->execute([$anticipo, $cita['negocio_usuario_id']]);
            $ledger->execute([
                $cita['id'],
                $cita['negocio_usuario_id'],
                $anticipo,
                "Compensación al negocio por inasistencia a cita #{$cita['id']}",
            ]);
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

    if (!$negocioId || !$servicioId || !$fechaInicio) {
        jsonResponse(['success' => false, 'error' => 'Datos de cita incompletos.'], 422);
    }

    try {
        $pdo->beginTransaction();

        // 1. Obtener datos del servicio y negocio
        $stmt = $pdo->prepare('
            SELECT s.nombre, s.duracion_minutos, s.monto_anticipo,
                   n.usuario_id AS dueno_id, n.plan_suscripcion
            FROM servicios s
            JOIN negocios n ON n.id = s.negocio_id
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

        // 3. Generar PIN de verificación de 4 dígitos
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

        // 5. Registrar movimiento en el libro mayor (movimientos_ledger)
        if ($anticipo > 0) {
            $insertLedger = $pdo->prepare("
                INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                VALUES (?, ?, 'retencion_anticipo', ?, ?)
            ");
            $insertLedger->execute([
                $citaId,
                $usuario['id'],
                $anticipo,
                "Retención de anticipo en custodia (Escrow) para cita #$citaId de " . $info['nombre'],
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
                'pin_verificacion'  => $pin,
                'monto_anticipo'    => $anticipo,
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
            c.monto_anticipo, c.pin_verificacion, c.created_at,
            s.nombre AS nombre_servicio,
            s.duracion_minutos,
            u.nombre AS nombre_cliente,
            u.telefono AS telefono_cliente,
            n.nombre AS nombre_negocio,
            n.ventana_cancelacion_horas
        FROM citas c
        JOIN servicios s ON s.id = c.servicio_id
        JOIN usuarios u ON u.id = c.cliente_id
        JOIN negocios n ON n.id = c.negocio_id
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
 * POST /api/citas.php?action=iniciar
 * Negocio valida el PIN de 4 dígitos presentado por el cliente en el local.
 */
function handleIniciarCitaConPin(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $citaId  = filter_var($body['cita_id'] ?? null, FILTER_VALIDATE_INT);
    $pin     = trim((string) ($body['pin'] ?? ''));

    if (!$citaId || !preg_match('/^\d{4}$/', $pin)) {
        jsonResponse(['success' => false, 'error' => 'Se requiere cita_id y un PIN de 4 dígitos numéricos.'], 422);
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare("
        SELECT c.id, c.pin_verificacion, c.estado
        FROM citas c
        JOIN negocios n ON n.id = c.negocio_id
        WHERE c.id = ? AND n.usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$citaId, $usuario['id']]);
    $cita = $stmt->fetch();

    if (!$cita) {
        jsonResponse(['success' => false, 'error' => 'Cita no encontrada.'], 404);
    }

    if (!in_array($cita['estado'], ['agendada', 'confirmada'], true)) {
        jsonResponse(['success' => false, 'error' => 'La cita no se encuentra en estado para ser iniciada.'], 400);
    }

    if ($cita['pin_verificacion'] !== $pin) {
        jsonResponse(['success' => false, 'error' => 'PIN incorrecto. Solicita al cliente que verifique su código en Mis Citas.'], 403);
    }

    $update = $pdo->prepare("UPDATE citas SET estado = 'en_curso' WHERE id = ?");
    $update->execute([$citaId]);

    jsonResponse(['success' => true, 'mensaje' => 'PIN verificado. Cita marcada como EN CURSO.']);
}

/**
 * POST /api/citas.php?action=completar
 * Finaliza la cita y libera el anticipo retenido al saldo del negocio.
 */
function handleCompletarCita(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $citaId  = filter_var($body['cita_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$citaId) jsonResponse(['success' => false, 'error' => 'cita_id inválido.'], 422);

    $pdo = getPDO();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT c.id, c.monto_anticipo, n.usuario_id AS negocio_usuario_id
            FROM citas c
            JOIN negocios n ON n.id = c.negocio_id
            WHERE c.id = ? AND n.usuario_id = ? AND c.estado = 'en_curso'
            FOR UPDATE
        ");
        $stmt->execute([$citaId, $usuario['id']]);
        $cita = $stmt->fetch();

        if (!$cita) {
            throw new \RuntimeException('La cita debe estar en estado EN CURSO para poder completarse.');
        }

        // Marcar como completada
        $pdo->prepare("UPDATE citas SET estado = 'completada' WHERE id = ?")->execute([$citaId]);

        $anticipo = (float) $cita['monto_anticipo'];
        if ($anticipo > 0) {
            // Acreditar al monedero del negocio
            $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
                ->execute([$anticipo, $cita['negocio_usuario_id']]);

            // Registrar liberación en auditoría
            $pdo->prepare("
                INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                VALUES (?, ?, 'liberacion_negocio', ?, ?)
            ")->execute([
                $citaId,
                $cita['negocio_usuario_id'],
                $anticipo,
                "Liberación de anticipo al negocio por cita completada #$citaId",
            ]);
        }

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'mensaje' => 'Cita completada. Anticipo liberado al saldo de tu negocio.',
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/**
 * POST /api/citas.php?action=cancelar
 * Procesa la cancelación aplicando la regla de ventana de tiempo:
 * - A tiempo: Reembolso 100% al saldo monedero del cliente
 * - Tarde / fuera de tiempo: El anticipo se transfiere al negocio como compensación
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
                   n.usuario_id AS dueno_negocio_id, n.ventana_cancelacion_horas
            FROM citas c
            JOIN negocios n ON n.id = c.negocio_id
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
        $horasRestantes = ($fechaInicio->getTimestamp() - $ahora->getTimestamp()) / 3600;

        $ventana = (int) $cita['ventana_cancelacion_horas'];
        $anticipo = (float) $cita['monto_anticipo'];

        $esATiempo = ($horasRestantes >= $ventana) || ($usuario['id'] === (int) $cita['dueno_negocio_id']);

        if ($esATiempo) {
            // Cancelada a tiempo -> 100% reembolsado al monedero del cliente
            $nuevoEstado = 'cancelada_tiempo';

            $pdo->prepare("UPDATE citas SET estado = ?, monto_anticipo_retenido = 0 WHERE id = ?")
                ->execute([$nuevoEstado, $citaId]);

            if ($anticipo > 0) {
                $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
                    ->execute([$anticipo, $cita['cliente_id']]);

                $pdo->prepare("
                    INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                    VALUES (?, ?, 'reembolso_cliente', ?, ?)
                ")->execute([
                    $citaId,
                    $cita['cliente_id'],
                    $anticipo,
                    "Reembolso del 100% de anticipo por cancelación a tiempo de cita #$citaId",
                ]);
            }

            $mensaje = 'Cita cancelada a tiempo. El 100% del anticipo ha sido reembolsado a tu monedero.';

        } else {
            // Cancelada tarde -> Anticipo transferido al negocio como compensación
            $nuevoEstado = 'cancelada_tarde';

            $pdo->prepare("UPDATE citas SET estado = ?, monto_anticipo_retenido = ? WHERE id = ?")
                ->execute([$nuevoEstado, $anticipo, $citaId]);

            if ($anticipo > 0) {
                $pdo->prepare('UPDATE usuarios SET saldo_monedero = saldo_monedero + ? WHERE id = ?')
                    ->execute([$anticipo, $cita['dueno_negocio_id']]);

                $pdo->prepare("
                    INSERT INTO movimientos_ledger (cita_id, usuario_id, tipo, monto, descripcion)
                    VALUES (?, ?, 'compensacion_negocio', ?, ?)
                ")->execute([
                    $citaId,
                    $cita['dueno_negocio_id'],
                    $anticipo,
                    "Compensación al negocio por cancelación tardía de cita #$citaId",
                ]);
            }

            $mensaje = 'Cita cancelada fuera del límite permitido. El anticipo ha sido asignado al negocio como penalización.';
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
