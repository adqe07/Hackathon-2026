<?php
/**
 * api/auth.php
 * -------------------------------------------------
 * Endpoint REST de autenticación.
 * Acciones disponibles (parámetro GET ?action=):
 *   register  — Crea una nueva cuenta de usuario
 *   login     — Inicia sesión y crea sesión PHP
 *   logout    — Destruye la sesión activa
 *   me        — Devuelve datos del usuario autenticado
 * -------------------------------------------------
 * Plataforma Hiperlocal | Fase 1: Cimientos
 * -------------------------------------------------
 */

declare(strict_types=1);

// ── Cabeceras CORS para desarrollo local ─────────
// En producción restringe el origen al dominio real.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Responder inmediatamente a preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Dependencias ─────────────────────────────────
require_once __DIR__ . '/config/db.php';

// ── Iniciar sesión PHP de forma segura ───────────
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_strict_mode', '1');
    session_start();
}

// ── Enrutamiento por acción ──────────────────────
$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match ($action) {
    'register' => handleRegister($method),
    'login'    => handleLogin($method),
    'logout'   => handleLogout($method),
    'me'       => handleMe($method),
    default    => jsonResponse([
        'success' => false,
        'error'   => "Acción '$action' no válida. Usa: register, login, logout, me.",
    ], 400),
};


// =============================================================================
// REGISTER — POST /api/auth.php?action=register
// =============================================================================
/**
 * Crea una nueva cuenta. El campo `rol` acepta 'cliente' o 'negocio'.
 * No permite crear usuarios con rol 'admin' desde este endpoint.
 *
 * Body JSON esperado:
 * {
 *   "nombre":    "Ana García",
 *   "email":     "ana@ejemplo.mx",
 *   "password":  "MiPassword123!",
 *   "telefono":  "5512345678",   (opcional)
 *   "rol":       "cliente"       (opcional, default: "cliente")
 * }
 */
function handleRegister(string $method): never
{
    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
    }

    $body = getJsonInput();

    // ── Validación de campos requeridos ──────────
    $nombre   = trim($body['nombre']   ?? '');
    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');
    $telefono = trim($body['telefono'] ?? '');
    $rol      = trim($body['rol']      ?? 'cliente');

    $errores = [];

    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
        $errores[] = 'El nombre debe tener entre 2 y 120 caracteres.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico no tiene un formato válido.';
    }

    if (mb_strlen($password) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errores[] = 'La contraseña debe contener al menos una mayúscula.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errores[] = 'La contraseña debe contener al menos un número.';
    }

    // Solo se permiten roles públicos; 'admin' se asigna manualmente en BD.
    if (!in_array($rol, ['cliente', 'negocio'], true)) {
        $errores[] = "El rol '$rol' no es válido. Elige 'cliente' o 'negocio'.";
    }

    if (!empty($errores)) {
        jsonResponse(['success' => false, 'errores' => $errores], 422);
    }

    // ── Verificar que el email no esté registrado ─
    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        jsonResponse([
            'success' => false,
            'error'   => 'Ya existe una cuenta con ese correo electrónico.',
        ], 409);
    }

    // ── Crear usuario ─────────────────────────────
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $insert = $pdo->prepare(
        'INSERT INTO usuarios (nombre, email, password_hash, telefono, rol)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $nombre,
        mb_strtolower($email),
        $hash,
        $telefono ?: null,
        $rol,
    ]);

    $nuevoId = (int) $pdo->lastInsertId();

    // ── Iniciar sesión automáticamente tras registro ─
    $usuario = [
        'id'     => $nuevoId,
        'nombre' => $nombre,
        'email'  => mb_strtolower($email),
        'rol'    => $rol,
        'saldo_monedero' => '0.00',
    ];
    $_SESSION['usuario'] = $usuario;
    // Regenerar ID de sesión para prevenir session fixation
    session_regenerate_id(true);

    jsonResponse([
        'success' => true,
        'mensaje' => '¡Cuenta creada exitosamente! Sesión iniciada.',
        'usuario' => $usuario,
    ], 201);
}


// =============================================================================
// LOGIN — POST /api/auth.php?action=login
// =============================================================================
/**
 * Verifica credenciales y crea la sesión PHP.
 *
 * Body JSON esperado:
 * {
 *   "email":    "ana@ejemplo.mx",
 *   "password": "MiPassword123!"
 * }
 */
function handleLogin(string $method): never
{
    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa POST.'], 405);
    }

    $body = getJsonInput();

    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');

    if (!$email || !$password) {
        jsonResponse([
            'success' => false,
            'error'   => 'Los campos email y password son obligatorios.',
        ], 422);
    }

    $pdo = getPDO();

    // ── Buscar usuario por email ──────────────────
    $stmt = $pdo->prepare(
        'SELECT id, nombre, email, password_hash, rol, saldo_monedero, activo
           FROM usuarios
          WHERE email = ?
          LIMIT 1'
    );
    $stmt->execute([mb_strtolower($email)]);
    $usuario = $stmt->fetch();

    // ── Validación en tiempo constante ───────────
    // Siempre se ejecuta password_verify() para evitar timing attacks.
    $hashFalso = '$2y$12$invalidhashpadding000000000000000000000000000000000000000';
    $hashReal  = $usuario['password_hash'] ?? $hashFalso;
    $passwordCorrecto = password_verify($password, $hashReal);

    if (!$usuario || !$passwordCorrecto) {
        jsonResponse([
            'success' => false,
            'error'   => 'Correo o contraseña incorrectos.',
        ], 401);
    }

    if (!$usuario['activo']) {
        jsonResponse([
            'success' => false,
            'error'   => 'Tu cuenta ha sido desactivada. Contacta al soporte.',
        ], 403);
    }

    // ── Si el hash es obsoleto, re-hashear ───────
    if (password_needs_rehash($usuario['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $nuevoHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([$nuevoHash, $usuario['id']]);
    }

    // ── Crear sesión ──────────────────────────────
    $datosSession = [
        'id'             => (int) $usuario['id'],
        'nombre'         => $usuario['nombre'],
        'email'          => $usuario['email'],
        'rol'            => $usuario['rol'],
        'saldo_monedero' => $usuario['saldo_monedero'],
    ];
    $_SESSION['usuario'] = $datosSession;
    session_regenerate_id(true);

    jsonResponse([
        'success' => true,
        'mensaje' => '¡Bienvenido de vuelta, ' . $usuario['nombre'] . '!',
        'usuario' => $datosSession,
    ]);
}


// =============================================================================
// LOGOUT — POST/GET /api/auth.php?action=logout
// =============================================================================
/**
 * Destruye la sesión activa y borra la cookie de sesión en el navegador.
 */
function handleLogout(string $method): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Vaciar variables de sesión
    $_SESSION = [];

    // Invalidar cookie de sesión PHPSESSID en todas las rutas posibles
    if (ini_get('session.use_cookies')) {
        $cookieName = session_name();
        setcookie($cookieName, '', time() - 42000, '/');
        
        $params = session_get_cookie_params();
        setcookie(
            $cookieName,
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?: '/',
                'domain'   => $params['domain'] ?: '',
                'secure'   => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => 'Lax',
            ]
        );
    }

    @session_destroy();

    jsonResponse([
        'success' => true,
        'mensaje' => 'Sesión cerrada correctamente.',
    ]);
}


// =============================================================================
// ME — GET /api/auth.php?action=me
// =============================================================================
/**
 * Devuelve los datos del usuario autenticado actualmente.
 * Refresca el saldo_monedero desde la BD en cada llamada.
 */
function handleMe(string $method): never
{
    if ($method !== 'GET') {
        jsonResponse(['success' => false, 'error' => 'Método no permitido. Usa GET.'], 405);
    }

    if (empty($_SESSION['usuario'])) {
        jsonResponse([
            'success'       => false,
            'autenticado'   => false,
            'error'         => 'No hay sesión activa.',
        ], 401);
    }

    $idSession = (int) $_SESSION['usuario']['id'];
    $pdo = getPDO();

    // Refrescar datos actuales desde BD (saldo, nombre, etc.)
    $stmt = $pdo->prepare(
        'SELECT id, nombre, email, telefono, rol, saldo_monedero,
                avatar_url, email_verificado, activo, created_at
           FROM usuarios
          WHERE id = ?
            AND activo = 1
          LIMIT 1'
    );
    $stmt->execute([$idSession]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // El usuario fue desactivado mientras tenía sesión abierta
        $_SESSION = [];
        session_destroy();
        jsonResponse([
            'success'     => false,
            'autenticado' => false,
            'error'       => 'Cuenta no encontrada o desactivada.',
        ], 401);
    }

    // Si el negocio tiene datos de negocio, incluirlos
    $negocio = null;
    if ($usuario['rol'] === 'negocio') {
        $stmtN = $pdo->prepare(
            'SELECT id, nombre, categoria, plan_suscripcion, verificado, activo
               FROM negocios
              WHERE usuario_id = ?
              LIMIT 1'
        );
        $stmtN->execute([$idSession]);
        $negocio = $stmtN->fetch() ?: null;
    }

    // Actualizar sesión con datos frescos
    $_SESSION['usuario'] = [
        'id'             => (int) $usuario['id'],
        'nombre'         => $usuario['nombre'],
        'email'          => $usuario['email'],
        'rol'            => $usuario['rol'],
        'saldo_monedero' => $usuario['saldo_monedero'],
    ];

    jsonResponse([
        'success'     => true,
        'autenticado' => true,
        'usuario'     => [
            'id'               => (int) $usuario['id'],
            'nombre'           => $usuario['nombre'],
            'email'            => $usuario['email'],
            'telefono'         => $usuario['telefono'],
            'rol'              => $usuario['rol'],
            'saldo_monedero'   => (float) $usuario['saldo_monedero'],
            'avatar_url'       => $usuario['avatar_url'],
            'email_verificado' => (bool) $usuario['email_verificado'],
            'miembro_desde'    => $usuario['created_at'],
        ],
        'negocio'     => $negocio,
    ]);
}
