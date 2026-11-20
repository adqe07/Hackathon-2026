<?php
/**
 * api/negocios.php
 * -------------------------------------------------
 * Endpoint REST para consulta de perfil público de negocios,
 * KPIs para el panel del comerciante y actualización de datos.
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
    'perfil'    => handlePerfil($method),
    'dashboard' => handleDashboard($method),
    'crear'     => handleCrear($method),
    'actualizar'=> handleActualizar($method),
    default     => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida en negocios.php. Usa: perfil, dashboard, crear, actualizar.",
    ], 400),
};

function handleCrear(string $method): never
{
    if ($method !== 'POST') jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa POST.'], 405);

    $usuario = requireAuth('negocio');
    $body = getJsonInput();
    $nombre = trim($body['nombre'] ?? '');
    $categoria = trim($body['categoria'] ?? '');
    $descripcion = trim($body['descripcion'] ?? '');
    $telefono = trim($body['telefono_contacto'] ?? '');
    $direccion = trim($body['direccion'] ?? '');
    $ciudad = trim($body['ciudad'] ?? '');
    $ventana = filter_var($body['ventana_cancelacion_horas'] ?? 24, FILTER_VALIDATE_INT);
    $latitud = validarCoordenada($body['latitud'] ?? null, -90, 90, 'latitud');
    $longitud = validarCoordenada($body['longitud'] ?? null, -180, 180, 'longitud');

    if ($nombre === '' || mb_strlen($nombre) > 150) {
        jsonResponse(['success' => false, 'error' => 'El nombre del negocio es obligatorio y admite hasta 150 caracteres.'], 422);
    }
    if (!in_array($categoria, categoriasPermitidas(), true)) {
        jsonResponse(['success' => false, 'error' => 'Selecciona una categoría válida.'], 422);
    }
    if (mb_strlen($descripcion) > 5000 || mb_strlen($telefono) > 20 || mb_strlen($direccion) > 300 || mb_strlen($ciudad) > 100) {
        jsonResponse(['success' => false, 'error' => 'Uno o más campos superan la longitud permitida.'], 422);
    }
    if ($ventana === false || $ventana < 1 || $ventana > 168) {
        jsonResponse(['success' => false, 'error' => 'La ventana de cancelación debe estar entre 1 y 168 horas.'], 422);
    }

    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $bloqueo = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'negocio' FOR UPDATE");
        $bloqueo->execute([$usuario['id']]);
        if (!$bloqueo->fetch()) throw new \RuntimeException('La cuenta no es válida para registrar un negocio.');

        $existente = $pdo->prepare('SELECT id FROM negocios WHERE usuario_id = ? LIMIT 1');
        $existente->execute([$usuario['id']]);
        if ($existente->fetch()) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Esta cuenta ya tiene un negocio configurado.'], 409);
        }

        $insert = $pdo->prepare('
            INSERT INTO negocios
                (usuario_id, nombre, categoria, descripcion, telefono_contacto,
                 direccion, ciudad, latitud, longitud, ventana_cancelacion_horas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            $usuario['id'], $nombre, $categoria, $descripcion ?: null,
            $telefono ?: null, $direccion ?: null, $ciudad ?: null,
            $latitud, $longitud, $ventana,
        ]);
        $negocioId = (int) $pdo->lastInsertId();
        $pdo->commit();

        jsonResponse([
            'success' => true,
            'mensaje' => 'Negocio configurado correctamente.',
            'negocio' => [
                'id' => $negocioId, 'nombre' => $nombre, 'categoria' => $categoria,
                'plan_suscripcion' => 'basico', 'verificado' => false,
            ],
        ], 201);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CREAR_NEGOCIO_ERROR] ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'No fue posible configurar el negocio.'], 500);
    }
}

function validarCoordenada(mixed $valor, float $minimo, float $maximo, string $campo): ?float
{
    if ($valor === null || $valor === '') return null;
    $coordenada = filter_var($valor, FILTER_VALIDATE_FLOAT);
    if ($coordenada === false || $coordenada < $minimo || $coordenada > $maximo) {
        jsonResponse(['success' => false, 'error' => "La $campo no es válida."], 422);
    }
    return (float) $coordenada;
}

function categoriasPermitidas(): array
{
    return ['estetica', 'barberia', 'perfumeria', 'bazar', 'boutique', 'taller',
            'tecnico', 'ropa', 'joyeria', 'comercio', 'otro'];
}

/**
 * GET /api/negocios.php?action=perfil&id=1&lat=...&lon=...
 * Perfil público del negocio con cálculo de distancia opcional.
 */
function handlePerfil(string $method): never
{
    if ($method !== 'GET') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
    }

    $id  = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);

    if (!$id) {
        jsonResponse(['success' => false, 'error' => 'El parámetro id de negocio es obligatorio.'], 422);
    }

    $hasGeo = ($lat !== false && $lon !== false && $lat !== null && $lon !== null);
    $pdo    = getPDO();

    if ($hasGeo) {
        $selectDistancia = '
            (6371 * ACOS(
                LEAST(1.0, GREATEST(-1.0,
                    COS(RADIANS(:lat1)) * COS(RADIANS(latitud))
                    * COS(RADIANS(longitud) - RADIANS(:lon))
                    + SIN(RADIANS(:lat2)) * SIN(RADIANS(latitud))
                ))
            )) AS distancia_km
        ';
    } else {
        $selectDistancia = 'NULL AS distancia_km';
    }

    $sql = "
        SELECT 
            id,
            usuario_id,
            nombre,
            categoria,
            descripcion,
            telefono_contacto,
            direccion,
            ciudad,
            latitud,
            longitud,
            logo_url,
            plan_suscripcion,
            ventana_cancelacion_horas,
            verificado,
            activo,
            $selectDistancia
        FROM negocios
        WHERE id = :id AND activo = 1
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($hasGeo) {
        $stmt->bindValue(':lat1', $lat);
        $stmt->bindValue(':lat2', $lat);
        $stmt->bindValue(':lon',  $lon);
    }
    $stmt->execute();

    $negocio = $stmt->fetch();

    if (!$negocio) {
        jsonResponse(['success' => false, 'error' => 'Negocio no encontrado o inactivo.'], 404);
    }

    $formateado = [
        'id'                        => (int) $negocio['id'],
        'usuario_id'                => (int) $negocio['usuario_id'],
        'nombre'                    => $negocio['nombre'],
        'categoria'                 => $negocio['categoria'],
        'descripcion'               => $negocio['descripcion'],
        'telefono_contacto'         => $negocio['telefono_contacto'],
        'direccion'                 => $negocio['direccion'],
        'ciudad'                    => $negocio['ciudad'],
        'latitud'                   => $negocio['latitud'] !== null ? (float) $negocio['latitud'] : null,
        'longitud'                  => $negocio['longitud'] !== null ? (float) $negocio['longitud'] : null,
        'logo_url'                  => $negocio['logo_url'],
        'pago_online_habilitado'    => $negocio['plan_suscripcion'] === 'pro',
        'ventana_cancelacion_horas' => (int) $negocio['ventana_cancelacion_horas'],
        'verificado'                => (bool) $negocio['verificado'],
        'distancia_km'              => $negocio['distancia_km'] !== null ? round((float) $negocio['distancia_km'], 2) : null,
    ];

    jsonResponse([
        'success' => true,
        'negocio' => $formateado,
    ]);
}

/**
 * GET /api/negocios.php?action=dashboard&negocio_id=1
 * Devuelve las métricas clave (KPIs) para el panel privado del negocio.
 */
function handleDashboard(string $method): never
{
    if ($method !== 'GET') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
    }

    $usuario = requireAuth('negocio');
    $pdo     = getPDO();

    $negocioId = filter_var($_GET['negocio_id'] ?? null, FILTER_VALIDATE_INT);

    // Verificar que el negocio pertenezca al usuario autenticado
    $stmtN = $pdo->prepare('SELECT id FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1');
    $stmtN->execute([$negocioId, $usuario['id']]);
    if (!$stmtN->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Negocio no válido o no autorizado.'], 403);
    }

    // 1. Total de productos activos
    $stmtP = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE negocio_id = ? AND activo = 1');
    $stmtP->execute([$negocioId]);
    $totalProductos = (int) $stmtP->fetchColumn();

    // 2. Citas agendadas/confirmadas para hoy
    $stmtC = $pdo->prepare("
        SELECT COUNT(*) 
        FROM citas 
        WHERE negocio_id = ? 
          AND DATE(fecha_hora_inicio) = CURDATE()
          AND estado IN ('agendada', 'confirmada', 'en_curso')
    ");
    $stmtC->execute([$negocioId]);
    $citasHoy = (int) $stmtC->fetchColumn();

    // 3. Apartados pendientes
    $stmtA = $pdo->prepare("
        SELECT COUNT(*) 
        FROM apartados 
        WHERE negocio_id = ? 
          AND estado = 'pendiente'
          AND fecha_expiracion > NOW()
    ");
    $stmtA->execute([$negocioId]);
    $apartadosPendientes = (int) $stmtA->fetchColumn();

    // 4. Saldo en custodia (Escrow): anticipos de citas activas pendientes de completarse
    $stmtEscrow = $pdo->prepare("
        SELECT COALESCE(SUM(monto_anticipo), 0)
        FROM citas
        WHERE negocio_id = ?
          AND estado IN ('agendada', 'confirmada', 'en_curso')
    ");
    $stmtEscrow->execute([$negocioId]);
    $saldoEscrow = (float) $stmtEscrow->fetchColumn();

    // 5. Ingresos este mes (anticipos liberados + compensaciones + apartados pagados)
    $stmtIngresos = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0)
        FROM movimientos_ledger
        WHERE usuario_id = ?
          AND tipo IN ('liberacion_negocio', 'compensacion_negocio', 'pago_online')
          AND MONTH(created_at) = MONTH(CURRENT_DATE())
          AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $stmtIngresos->execute([$usuario['id']]);
    $ingresosMes = (float) $stmtIngresos->fetchColumn();

    jsonResponse([
        'success' => true,
        'kpis'    => [
            'total_productos'      => $totalProductos,
            'citas_hoy'            => $citasHoy,
            'apartados_pendientes' => $apartadosPendientes,
            'saldo_escrow'         => $saldoEscrow,
            'ingresos_mes'         => $ingresosMes,
        ],
    ]);
}

/**
 * POST /api/negocios.php?action=actualizar
 * Actualiza la información del negocio (dirección, coordenadas, teléfono, etc.).
 */
function handleActualizar(string $method): never
{
    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
    }

    $usuario = requireAuth('negocio');
    $body    = getJsonInput();
    $pdo     = getPDO();

    $negocioId = filter_var($body['negocio_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$negocioId) {
        jsonResponse(['success' => false, 'error' => 'El id de negocio es obligatorio.'], 422);
    }

    // Verificar propiedad
    $stmtN = $pdo->prepare('SELECT id FROM negocios WHERE id = ? AND usuario_id = ? LIMIT 1');
    $stmtN->execute([$negocioId, $usuario['id']]);
    if (!$stmtN->fetch()) {
        jsonResponse(['success' => false, 'error' => 'No autorizado para editar este negocio.'], 403);
    }

    $nombre          = trim($body['nombre'] ?? '');
    $categoria       = trim($body['categoria'] ?? '');
    $descripcion     = trim($body['descripcion'] ?? '');
    $telefono        = trim($body['telefono_contacto'] ?? '');
    $direccion       = trim($body['direccion'] ?? '');
    $ciudad          = trim($body['ciudad'] ?? '');
    $latitud         = filter_var($body['latitud'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitud        = filter_var($body['longitud'] ?? null, FILTER_VALIDATE_FLOAT);
    $ventanaCancel   = filter_var($body['ventana_cancelacion_horas'] ?? 24, FILTER_VALIDATE_INT);

    if ($nombre === '') {
        jsonResponse(['success' => false, 'error' => 'El nombre del negocio es obligatorio.'], 422);
    }
    if (!in_array($categoria, categoriasPermitidas(), true)) {
        jsonResponse(['success' => false, 'error' => 'La categoría seleccionada no está permitida.'], 422);
    }

    $updateSql = '
        UPDATE negocios SET
            nombre = ?,
            categoria = ?,
            descripcion = ?,
            telefono_contacto = ?,
            direccion = ?,
            ciudad = ?,
            latitud = ?,
            longitud = ?,
            ventana_cancelacion_horas = ?
        WHERE id = ?
    ';

    $update = $pdo->prepare($updateSql);
    $update->execute([
        $nombre,
        $categoria ?: 'comercio',
        $descripcion ?: null,
        $telefono ?: null,
        $direccion ?: null,
        $ciudad ?: null,
        $latitud !== false ? $latitud : null,
        $longitud !== false ? $longitud : null,
        $ventanaCancel ?: 24,
        $negocioId,
    ]);

    jsonResponse([
        'success' => true,
        'mensaje' => 'Datos del negocio actualizados con éxito.',
    ]);
}
