<?php
/**
 * api/buscar.php
 * -------------------------------------------------
 * Endpoint REST para búsqueda por cercanía de negocios.
 * Implementa la Fórmula de Haversine para cálculo de
 * distancia en kilómetros a partir de coordenadas GPS.
 * -------------------------------------------------
 * Plataforma Mova | Fase 2: Backend API
 * -------------------------------------------------
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/db.php';

$action = trim($_GET['action'] ?? 'buscar');
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
}

if ($action !== 'buscar') {
    jsonResponse(['success' => false, 'error' => "Acción '$action' no válida. Usa: buscar."], 400);
}

handleBuscar();

/**
 * Realiza la búsqueda de negocios aplicando:
 * - Filtro de texto libre (nombre, descripción, productos, servicios)
 * - Filtro por categoría
 * - Cálculo de distancia geográfica (Haversine en km) si se proporcionan latitud y longitud
 * - Ordenamiento prioritario: `plan_suscripcion = 'pro'` primero, luego `distancia_km ASC`
 * - Paginación con límite y offset
 */
function handleBuscar(): never
{
    $pdo = getPDO();

    $q         = trim($_GET['q'] ?? '');
    $categoria = trim($_GET['categoria'] ?? '');
    $lat       = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lon       = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);
    $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
    $limite    = min(50, max(1, (int) ($_GET['limite'] ?? 12)));
    $offset    = ($pagina - 1) * $limite;

    $hasGeo = ($lat !== false && $lon !== false && $lat !== null && $lon !== null);

    // Parámetros exclusivos para la cláusula WHERE (usados en count y en main)
    $whereParts  = ['n.activo = 1'];
    $whereParams = [];

    if ($categoria !== '') {
        $whereParts[] = 'n.categoria = :categoria';
        $whereParams[':categoria'] = mb_strtolower($categoria);
    }

    if ($q !== '') {
        $whereParts[] = '
            (
                n.nombre LIKE :term_nombre
                OR n.descripcion LIKE :term_descripcion
                OR n.categoria LIKE :term_categoria
                OR EXISTS (
                    SELECT 1 FROM productos p
                    WHERE p.negocio_id = n.id
                      AND p.activo = 1
                      AND (p.titulo LIKE :term_producto_titulo OR p.descripcion LIKE :term_producto_descripcion)
                )
                OR EXISTS (
                    SELECT 1 FROM servicios s
                    WHERE s.negocio_id = n.id
                      AND s.activo = 1
                      AND (s.nombre LIKE :term_servicio_nombre OR s.descripcion LIKE :term_servicio_descripcion)
                )
            )
        ';
        $term = '%' . $q . '%';
        $whereParams[':term_nombre'] = $term;
        $whereParams[':term_descripcion'] = $term;
        $whereParams[':term_categoria'] = $term;
        $whereParams[':term_producto_titulo'] = $term;
        $whereParams[':term_producto_descripcion'] = $term;
        $whereParams[':term_servicio_nombre'] = $term;
        $whereParams[':term_servicio_descripcion'] = $term;
    }

    $whereSQL = implode(' AND ', $whereParts);

    // 1. Consulta de conteo total (solo recibe los parámetros presentes en el WHERE)
    $countSql  = "SELECT COUNT(DISTINCT n.id) AS total FROM negocios n WHERE $whereSQL";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($whereParams);
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    // 2. Fragmento de cálculo de distancia mediante Haversine
    // Usamos marcadores únicos (:lat1, :lat2, :lon) para compatibilidad total con PDO
    if ($hasGeo) {
        $selectDistancia = '
            (6371 * ACOS(
                LEAST(1.0, GREATEST(-1.0,
                    COS(RADIANS(:lat1)) * COS(RADIANS(n.latitud))
                    * COS(RADIANS(n.longitud) - RADIANS(:lon))
                    + SIN(RADIANS(:lat2)) * SIN(RADIANS(n.latitud))
                ))
            )) AS distancia_km
        ';
        $orderBy = "ORDER BY (CASE WHEN n.plan_suscripcion = 'pro' THEN 1 ELSE 0 END) DESC, distancia_km ASC, n.id DESC";
    } else {
        $selectDistancia = 'NULL AS distancia_km';
        $orderBy = "ORDER BY (CASE WHEN n.plan_suscripcion = 'pro' THEN 1 ELSE 0 END) DESC, n.id DESC";
    }

    // 3. Consulta principal
    $sql = "
        SELECT 
            n.id,
            n.nombre,
            n.categoria,
            n.descripcion,
            n.telefono_contacto,
            n.direccion,
            n.ciudad,
            n.latitud,
            n.longitud,
            n.logo_url,
            n.plan_suscripcion,
            n.verificado,
            $selectDistancia
        FROM negocios n
        WHERE $whereSQL
        $orderBy
        LIMIT :limite OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    // Vincular parámetros de la cláusula WHERE
    foreach ($whereParams as $paramKey => $val) {
        $stmt->bindValue($paramKey, $val);
    }

    // Vincular coordenadas únicas si existen
    if ($hasGeo) {
        $stmt->bindValue(':lat1', $lat);
        $stmt->bindValue(':lat2', $lat);
        $stmt->bindValue(':lon',  $lon);
    }

    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $resultados = $stmt->fetchAll();

    // Formatear tipos de datos numéricos en el resultado
    $formateados = array_map(function ($row) {
        return [
            'id'                => (int) $row['id'],
            'nombre'            => $row['nombre'],
            'categoria'         => $row['categoria'],
            'descripcion'       => $row['descripcion'],
            'telefono_contacto' => $row['telefono_contacto'],
            'direccion'         => $row['direccion'],
            'ciudad'            => $row['ciudad'],
            'latitud'           => $row['latitud'] !== null ? (float) $row['latitud'] : null,
            'longitud'          => $row['longitud'] !== null ? (float) $row['longitud'] : null,
            'logo_url'          => $row['logo_url'],
            'plan_suscripcion'  => $row['plan_suscripcion'],
            'verificado'        => (bool) $row['verificado'],
            'distancia_km'      => $row['distancia_km'] !== null ? round((float) $row['distancia_km'], 2) : null,
        ];
    }, $resultados);

    jsonResponse([
        'success'    => true,
        'total'      => $total,
        'pagina'     => $pagina,
        'limite'     => $limite,
        'resultados' => $formateados,
    ]);
}
