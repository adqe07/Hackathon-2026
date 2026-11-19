-- =============================================================================
-- PLATAFORMA HIPERLOCAL DE COMERCIO Y SERVICIOS
-- Script: schema.sql
-- Motor: InnoDB | Charset: utf8mb4_unicode_ci
-- Versión: 1.0  |  Fase 1: Cimientos y Base de Datos
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '-06:00';
SET foreign_key_checks = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------------------------------
-- Base de datos
-- -----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS hiperlocal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hiperlocal;

-- =============================================================================
-- TABLA: usuarios
-- Almacena tanto clientes como dueños de negocio.
-- El campo `rol` distingue el tipo de usuario.
-- =============================================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(120)        NOT NULL,
    email           VARCHAR(180)        NOT NULL,
    password_hash   VARCHAR(255)        NOT NULL,
    telefono        VARCHAR(20)         DEFAULT NULL,
    rol             ENUM('cliente','negocio','admin')
                                        NOT NULL DEFAULT 'cliente',
    saldo_monedero  DECIMAL(10,2)       NOT NULL DEFAULT 0.00
                                        COMMENT 'Saldo en MXN acumulado por reembolsos de anticipos',
    avatar_url      VARCHAR(500)        DEFAULT NULL,
    email_verificado TINYINT(1)         NOT NULL DEFAULT 0,
    activo          TINYINT(1)          NOT NULL DEFAULT 1,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_rol (rol),
    KEY idx_usuarios_activo (activo)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Clientes y dueños de negocio';


-- =============================================================================
-- TABLA: negocios
-- Cada negocio pertenece a un usuario con rol "negocio".
-- =============================================================================
CREATE TABLE IF NOT EXISTS negocios (
    id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    usuario_id                  INT UNSIGNED    NOT NULL,
    nombre                      VARCHAR(150)    NOT NULL,
    categoria                   VARCHAR(100)    NOT NULL
                                                COMMENT 'ej: estetica, barberia, perfumeria, bazar, taller',
    descripcion                 TEXT            DEFAULT NULL,
    telefono_contacto           VARCHAR(20)     DEFAULT NULL,
    direccion                   VARCHAR(300)    DEFAULT NULL,
    ciudad                      VARCHAR(100)    DEFAULT NULL,
    latitud                     DECIMAL(10,8)   DEFAULT NULL
                                                COMMENT 'WGS84 - precisión ~1.1 mm',
    longitud                    DECIMAL(11,8)   DEFAULT NULL
                                                COMMENT 'WGS84 - precisión ~1.1 mm',
    logo_url                    VARCHAR(500)    DEFAULT NULL,
    plan_suscripcion            ENUM('basico','pro')
                                                NOT NULL DEFAULT 'basico',
    ventana_cancelacion_horas   TINYINT UNSIGNED NOT NULL DEFAULT 24
                                                COMMENT 'Horas antes de la cita para cancelar sin penalización',
    verificado                  TINYINT(1)      NOT NULL DEFAULT 0,
    activo                      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_negocios_usuario    (usuario_id),
    KEY idx_negocios_categoria  (categoria),
    KEY idx_negocios_plan       (plan_suscripcion),
    KEY idx_negocios_coords     (latitud, longitud),
    KEY idx_negocios_activo     (activo),
    CONSTRAINT fk_negocios_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Perfil y configuración de cada negocio local';


-- =============================================================================
-- TABLA: productos
-- Catálogo de artículos (no comida) por negocio.
-- =============================================================================
CREATE TABLE IF NOT EXISTS productos (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    negocio_id      INT UNSIGNED        NOT NULL,
    titulo          VARCHAR(200)        NOT NULL,
    descripcion     TEXT                DEFAULT NULL,
    categoria       VARCHAR(100)        DEFAULT NULL,
    precio          DECIMAL(10,2)       NOT NULL,
    stock_total     INT UNSIGNED        NOT NULL DEFAULT 0
                                        COMMENT 'Stock real en bodega',
    stock_disponible INT UNSIGNED       NOT NULL DEFAULT 0
                                        COMMENT 'Stock real menos unidades en apartado activo',
    imagen_url      VARCHAR(500)        DEFAULT NULL,
    activo          TINYINT(1)          NOT NULL DEFAULT 1,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_negocio   (negocio_id),
    KEY idx_productos_activo    (activo),
    KEY idx_productos_stock     (stock_disponible),
    FULLTEXT KEY ft_productos_titulo_desc (titulo, descripcion),
    CONSTRAINT fk_productos_negocio FOREIGN KEY (negocio_id)
        REFERENCES negocios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Inventario de productos por negocio';


-- =============================================================================
-- TABLA: apartados
-- Registra cada reserva/apartado de producto (efectivo o pago online).
-- TTL de apartado en efectivo: 3 horas.
-- =============================================================================
CREATE TABLE IF NOT EXISTS apartados (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    producto_id     INT UNSIGNED        NOT NULL,
    cliente_id      INT UNSIGNED        NOT NULL,
    negocio_id      INT UNSIGNED        NOT NULL
                                        COMMENT 'Desnormalización para consultas de panel',
    cantidad        TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    tipo_pago       ENUM('efectivo_tienda','online')
                                        NOT NULL,
    estado          ENUM('pendiente','pagado','expirado','cancelado')
                                        NOT NULL DEFAULT 'pendiente',
    precio_unitario DECIMAL(10,2)       NOT NULL
                                        COMMENT 'Precio al momento del apartado (histórico)',
    monto_total     DECIMAL(10,2)       NOT NULL,
    fecha_expiracion DATETIME           NOT NULL
                                        COMMENT 'Para efectivo: now()+3h; para online: se actualiza al pagar',
    notas_cliente   VARCHAR(500)        DEFAULT NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_apartados_producto      (producto_id),
    KEY idx_apartados_cliente       (cliente_id),
    KEY idx_apartados_negocio       (negocio_id),
    KEY idx_apartados_estado        (estado),
    KEY idx_apartados_expiracion    (fecha_expiracion),
    CONSTRAINT fk_apartados_producto FOREIGN KEY (producto_id)
        REFERENCES productos (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_apartados_cliente FOREIGN KEY (cliente_id)
        REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_apartados_negocio FOREIGN KEY (negocio_id)
        REFERENCES negocios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Apartados de productos con TTL de expiración';


-- =============================================================================
-- TABLA: servicios
-- Catálogo de servicios ofrecidos por cada negocio (citas con anticipo).
-- =============================================================================
CREATE TABLE IF NOT EXISTS servicios (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    negocio_id          INT UNSIGNED    NOT NULL,
    nombre              VARCHAR(150)    NOT NULL,
    descripcion         TEXT            DEFAULT NULL,
    duracion_minutos    SMALLINT UNSIGNED NOT NULL DEFAULT 60
                                        COMMENT 'Duración del servicio para bloquear horario',
    precio_estimado     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    monto_anticipo      DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                        COMMENT 'Monto fijo requerido como anticipo para agendar',
    activo              TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_servicios_negocio (negocio_id),
    KEY idx_servicios_activo  (activo),
    FULLTEXT KEY ft_servicios_nombre_desc (nombre, descripcion),
    CONSTRAINT fk_servicios_negocio FOREIGN KEY (negocio_id)
        REFERENCES negocios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Servicios disponibles para agendar cita';


-- =============================================================================
-- TABLA: citas
-- Máquina de estados para citas con anticipo en custodia (escrow).
-- Estados posibles:
--   pendiente_pago   → Bloquea slot 10 min mientras se paga anticipo
--   agendada         → Anticipo retenido en escrow
--   confirmada       → Aceptada explícitamente por el negocio
--   en_curso         → Iniciada con PIN de 4 dígitos del cliente
--   completada       → Anticipo liberado al negocio
--   cancelada_tiempo → Cancelación antes del límite → reembolso 100%
--   cancelada_tarde  → Cancelación fuera del límite → anticipo al negocio
--   no_show          → 15 min de tolerancia sin PIN → anticipo al negocio
-- =============================================================================
CREATE TABLE IF NOT EXISTS citas (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    servicio_id             INT UNSIGNED    NOT NULL,
    negocio_id              INT UNSIGNED    NOT NULL
                                            COMMENT 'Desnormalización para agenda del negocio',
    cliente_id              INT UNSIGNED    NOT NULL,
    fecha_hora_inicio       DATETIME        NOT NULL,
    fecha_hora_fin          DATETIME        NOT NULL
                                            COMMENT 'Calculado: inicio + duracion_minutos del servicio',
    estado                  ENUM(
                                'pendiente_pago',
                                'agendada',
                                'confirmada',
                                'en_curso',
                                'completada',
                                'cancelada_tiempo',
                                'cancelada_tarde',
                                'no_show'
                            )               NOT NULL DEFAULT 'pendiente_pago',
    monto_anticipo          DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                            COMMENT 'Anticipo cobrado al momento de agendar (histórico)',
    monto_anticipo_retenido DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                            COMMENT 'Monto final retenido/transferido por penalización',
    pin_verificacion        CHAR(4)         DEFAULT NULL
                                            COMMENT 'PIN de 4 dígitos para iniciar servicio EN_CURSO',
    fecha_limite_pago       DATETIME        DEFAULT NULL
                                            COMMENT 'Expiración de ventana PENDIENTE_PAGO (now()+10 min)',
    notas_cliente           VARCHAR(500)    DEFAULT NULL,
    notas_negocio           VARCHAR(500)    DEFAULT NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_citas_servicio          (servicio_id),
    KEY idx_citas_negocio           (negocio_id),
    KEY idx_citas_cliente           (cliente_id),
    KEY idx_citas_estado            (estado),
    KEY idx_citas_fecha_inicio      (fecha_hora_inicio),
    KEY idx_citas_negocio_fecha     (negocio_id, fecha_hora_inicio, fecha_hora_fin),
    CONSTRAINT fk_citas_servicio FOREIGN KEY (servicio_id)
        REFERENCES servicios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_citas_negocio FOREIGN KEY (negocio_id)
        REFERENCES negocios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_citas_cliente FOREIGN KEY (cliente_id)
        REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Citas de servicio con máquina de estados y anticipo escrow';


-- =============================================================================
-- TABLA: movimientos_ledger
-- Libro mayor de auditoría financiera para todos los movimientos de dinero
-- relacionados con anticipos: retención, liberación y reembolso.
-- =============================================================================
CREATE TABLE IF NOT EXISTS movimientos_ledger (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    cita_id         INT UNSIGNED        DEFAULT NULL
                                        COMMENT 'NULL si el movimiento es por apartado online',
    apartado_id     INT UNSIGNED        DEFAULT NULL
                                        COMMENT 'NULL si el movimiento es por cita',
    usuario_id      INT UNSIGNED        NOT NULL
                                        COMMENT 'Usuario afectado (cliente o negocio)',
    tipo            ENUM(
                        'retencion_anticipo',
                        'liberacion_negocio',
                        'reembolso_cliente',
                        'compensacion_negocio',
                        'pago_online',
                        'devolucion_online'
                    )                   NOT NULL,
    monto           DECIMAL(10,2)       NOT NULL
                                        COMMENT 'Siempre positivo; el tipo indica la dirección',
    descripcion     VARCHAR(300)        DEFAULT NULL,
    referencia_pago VARCHAR(100)        DEFAULT NULL
                                        COMMENT 'ID de transacción externo (Stripe/MercadoPago)',
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ledger_cita         (cita_id),
    KEY idx_ledger_apartado     (apartado_id),
    KEY idx_ledger_usuario      (usuario_id),
    KEY idx_ledger_tipo         (tipo),
    KEY idx_ledger_created_at   (created_at),
    CONSTRAINT fk_ledger_cita FOREIGN KEY (cita_id)
        REFERENCES citas (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_ledger_apartado FOREIGN KEY (apartado_id)
        REFERENCES apartados (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_ledger_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Libro mayor de auditoría financiera (anticipos, reembolsos, compensaciones)';


-- =============================================================================
-- DATOS DE PRUEBA
-- =============================================================================

-- -------------------------
-- Usuarios de prueba
-- -------------------------
-- Contraseña para todos los usuarios de prueba: "Demo1234!"
-- Hash verificado con password_hash('Demo1234!', PASSWORD_BCRYPT, ['cost' => 12]):
-- $2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16
INSERT INTO usuarios (nombre, email, password_hash, telefono, rol, saldo_monedero, activo) VALUES
('Admin Sistema',      'admin@hiperlocal.mx',    '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5500000001', 'admin',   0.00, 1),
('Dueña Estética',     'estetica@demo.mx',       '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5511111111', 'negocio', 0.00, 1),
('Dueña Perfumería',   'perfumeria@demo.mx',     '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5522222222', 'negocio', 0.00, 1),
('Ana García',         'ana@cliente.mx',         '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5533333333', 'cliente', 150.00, 1),
('Luis Martínez',      'luis@cliente.mx',        '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5544444444', 'cliente', 0.00, 1);

-- -------------------------
-- Negocios de prueba
-- Coordenadas reales en CDMX
-- -------------------------
-- Negocio 1: Estética - Colonia Roma Norte, CDMX
-- Latitud: 19.41941870, Longitud: -99.16284600
-- Negocio 2: Perfumería - Colonia Polanco, CDMX
-- Latitud: 19.43200000, Longitud: -99.19250000
INSERT INTO negocios
    (usuario_id, nombre, categoria, descripcion, telefono_contacto, direccion, ciudad,
     latitud, longitud, plan_suscripcion, ventana_cancelacion_horas, verificado, activo)
VALUES
(
    2,
    'Estética Bella Imagen',
    'estetica',
    'Estética profesional con más de 10 años de experiencia. Ofrecemos corte, peinado, coloración, tratamientos capilares, manicure y pedicure. Trabajamos con productos de alta calidad. ¡Tu belleza es nuestra pasión!',
    '5511111111',
    'Orizaba 101, Col. Roma Norte, Cuauhtémoc',
    'Ciudad de México',
    19.41941870,
    -99.16284600,
    'pro',
    24,
    1,
    1
),
(
    3,
    'Perfumería Essence Luxe',
    'perfumeria',
    'Boutique de fragancias nacionales e internacionales. Encontrarás perfumes originales, decants, velas aromáticas y accesorios de belleza. También realizamos envíos locales y apartados en línea.',
    '5522222222',
    'Presidente Masaryk 123, Col. Polanco V Sección, Miguel Hidalgo',
    'Ciudad de México',
    19.43200000,
    -99.19250000,
    'basico',
    12,
    0,
    1
);

-- -------------------------
-- Productos de prueba
-- -------------------------
INSERT INTO productos
    (negocio_id, titulo, descripcion, categoria, precio, stock_total, stock_disponible, activo)
VALUES
-- Productos de la Estética (negocio_id = 1)
(
    1,
    'Kit Tratamiento Hidratante Capilar',
    'Set completo con shampoo, acondicionador y mascarilla hidratante profesional. Ideal para cabello seco y dañado. Rinde hasta 30 aplicaciones. Marca: HydraGloss Pro.',
    'cuidado_capilar',
    349.00,
    15,
    15,
    1
),
(
    1,
    'Tinte Semipermanente L\'Oréal - Rubio Dorado #7.3',
    'Tinte semipermanente sin amoniaco, cobertura hasta 70% de canas. Duración aproximada 6-8 semanas. Tono: Rubio Dorado 7.3. Incluye revelador 20 vol.',
    'tintes',
    189.00,
    30,
    30,
    1
),
-- Productos de la Perfumería (negocio_id = 2)
(
    2,
    'Perfume Dior Sauvage EDT 100ml (Original)',
    'Eau de Toilette masculino Dior Sauvage 100ml. Notas de bergamota, ambroxan y pimienta. Producto 100% original con garantía de autenticidad. Incluye caja sellada.',
    'perfumes_hombre',
    1850.00,
    8,
    8,
    1
),
(
    2,
    'Set Velas Aromáticas Artesanales (3 piezas)',
    'Set de 3 velas de soya artesanales en aromas: Vainilla & Sándalo, Lavanda & Menta, Bergamota & Cedro. 150g cada una. Tiempo de quema: ~40 horas por vela.',
    'aromaterapia',
    320.00,
    20,
    20,
    1
);

-- -------------------------
-- Servicios de prueba
-- -------------------------
INSERT INTO servicios
    (negocio_id, nombre, descripcion, duracion_minutos, precio_estimado, monto_anticipo, activo)
VALUES
-- Servicios de la Estética (negocio_id = 1)
(
    1,
    'Corte y Peinado Profesional',
    'Incluye lavado con productos premium, corte personalizado según tipo de cara, secado y peinado. Para todo tipo de cabello. Duración aproximada 60 minutos.',
    60,
    280.00,
    80.00,
    1
),
(
    1,
    'Coloración Completa con Tinte',
    'Aplicación de tinte en raíz y puntas, incluye revelador. Se realiza consulta de color previa. Puede incluir mechas o balayage con costo adicional. Duración aproximada 120 minutos.',
    120,
    650.00,
    200.00,
    1
),
(
    1,
    'Manicure Semipermanente',
    'Preparación de uñas, aplicación de base, color semipermanente y top coat con lámpara UV. Incluye diseño básico. Dura entre 2 y 3 semanas sin levantamiento.',
    75,
    220.00,
    60.00,
    1
);

-- Reactivar las llaves foráneas
SET foreign_key_checks = 1;

-- =============================================================================
-- NOTAS DE IMPLEMENTACIÓN
-- =============================================================================
-- 1. HAVERSINE en MySQL (uso en buscar.php):
--    La distancia en km entre dos puntos se calcula como:
--    (6371 * ACOS(
--        LEAST(1.0, COS(RADIANS(:lat)) * COS(RADIANS(latitud))
--        * COS(RADIANS(longitud) - RADIANS(:lon))
--        + SIN(RADIANS(:lat)) * SIN(RADIANS(latitud)))
--    )) AS distancia_km
--
-- 2. EXPIRACIÓN DE APARTADOS:
--    Al consultar disponibilidad, hacer primero:
--    UPDATE apartados
--       SET estado = 'expirado'
--     WHERE estado = 'pendiente'
--       AND tipo_pago = 'efectivo_tienda'
--       AND fecha_expiracion < NOW();
--    Luego restaurar stock_disponible con un trigger o en la misma transacción.
--
-- 3. VENTANA PENDIENTE_PAGO de citas:
--    Al crear cita, establecer:
--    fecha_limite_pago = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
--    Si expira sin pago, el horario se libera.
-- =============================================================================
