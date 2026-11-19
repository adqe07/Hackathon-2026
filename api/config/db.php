<?php
/**
 * api/config/db.php
 * -------------------------------------------------
 * Conexión PDO segura + helpers de respuesta JSON.
 * Plataforma Hiperlocal | Fase 1: Cimientos
 * -------------------------------------------------
 */

declare(strict_types=1);

// Iniciar buffer de salida para garantizar respuestas JSON limpias
if (ob_get_level() === 0) {
    ob_start();
}

// ─────────────────────────────────────────────────
// CONFIGURACIÓN — ajusta estos valores en producción
// con variables de entorno o un archivo .env propio.
// ─────────────────────────────────────────────────
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');
define('DB_NAME',    'hiperlocal');
define('DB_USER',    'root');
define('DB_PASS',    '');           // Cambia en producción
define('DB_CHARSET', 'utf8mb4');

// Zona horaria del servidor
date_default_timezone_set('America/Mexico_City');


// ─────────────────────────────────────────────────
// getPDO() — Singleton de conexión PDO
// ─────────────────────────────────────────────────
/**
 * Devuelve una instancia PDO compartida durante el ciclo de vida
 * de la petición. Lanza una excepción con mensaje seguro si falla.
 *
 * @return PDO
 * @throws RuntimeException
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // Sentencias preparadas reales
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('[DB_CONNECT_ERROR] ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'Error de conexión con la base de datos MySQL (puerto 3306). Verifica que el servicio MySQL esté iniciado en XAMPP.',
        ], 503);
    }

    return $pdo;
}


// ─────────────────────────────────────────────────
// jsonResponse() — Envía JSON y termina la ejecución
// ─────────────────────────────────────────────────
/**
 * Establece las cabeceras HTTP correctas, serializa $data como JSON
 * con el código de estado indicado y termina el script.
 *
 * @param  mixed $data        Datos a serializar.
 * @param  int   $statusCode  Código de estado HTTP (default 200).
 * @return never
 */
function jsonResponse(mixed $data, int $statusCode = 200): never
{
    // Limpiar cualquier salida previa (espacios, BOM, etc.)
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    // Flags para JSON legible y seguro en contextos HTML
    $flags = JSON_UNESCAPED_UNICODE
           | JSON_UNESCAPED_SLASHES
           | JSON_THROW_ON_ERROR;

    // En desarrollo puedes añadir JSON_PRETTY_PRINT
    // $flags |= JSON_PRETTY_PRINT;

    echo json_encode($data, $flags);
    exit;
}


// ─────────────────────────────────────────────────
// getJsonInput() — Lee y decodifica el body JSON
// ─────────────────────────────────────────────────
/**
 * Lee php://input, lo decodifica como JSON y devuelve un array asociativo.
 * Si el body no es JSON válido o está vacío, devuelve un array vacío.
 *
 * @return array<string, mixed>
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');

    if (empty($raw)) {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (\JsonException $e) {
        error_log('[JSON_INPUT_ERROR] ' . $e->getMessage());
        return [];
    }
}


// ─────────────────────────────────────────────────
// sanitizeString() — Limpieza básica de cadenas
// ─────────────────────────────────────────────────
/**
 * Elimina espacios extremos y convierte caracteres especiales a entidades HTML.
 * No reemplaza el uso de sentencias preparadas; es un refuerzo de presentación.
 *
 * @param  string $value
 * @return string
 */
function sanitizeString(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}


// ─────────────────────────────────────────────────
// requireAuth() — Verifica sesión activa
// ─────────────────────────────────────────────────
/**
 * Verifica que exista una sesión PHP válida con usuario autenticado.
 * Si no existe, responde con 401 y termina.
 * Si existe, devuelve los datos de sesión del usuario.
 *
 * @param  string|null $rolRequerido  Si se indica, valida que el rol coincida.
 * @return array<string, mixed>       Datos del usuario de sesión.
 */
function requireAuth(?string $rolRequerido = null): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['usuario'])) {
        jsonResponse([
            'success' => false,
            'error'   => 'No autenticado. Inicia sesión para continuar.',
        ], 401);
    }

    $usuario = $_SESSION['usuario'];

    if ($rolRequerido !== null && $usuario['rol'] !== $rolRequerido) {
        jsonResponse([
            'success' => false,
            'error'   => 'Acceso denegado. No tienes permisos para esta acción.',
        ], 403);
    }

    return $usuario;
}
