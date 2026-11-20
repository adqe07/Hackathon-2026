-- =============================================================================
-- MOVA | ETAPA 6: CONFIANZA, PAGOS Y DISPUTAS
-- Migración aditiva: no modifica tablas ni columnas existentes.
-- =============================================================================

USE mova;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS metodos_pago (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED NOT NULL,
    marca           VARCHAR(30) NOT NULL,
    ultimos4        CHAR(4) NOT NULL,
    titular         VARCHAR(120) NOT NULL,
    exp_mes         TINYINT UNSIGNED NOT NULL,
    exp_anio        SMALLINT UNSIGNED NOT NULL,
    token_simulado  VARCHAR(100) NOT NULL,
    predeterminada  TINYINT(1) NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metodo_token (token_simulado),
    KEY idx_metodos_usuario (usuario_id, activo),
    CONSTRAINT fk_metodos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios_config_pago (
    servicio_id         INT UNSIGNED NOT NULL,
    tipo_garantia       ENUM('ninguna','porcentaje','fijo') NOT NULL DEFAULT 'fijo',
    valor_garantia      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    permite_anticipo    TINYINT(1) NOT NULL DEFAULT 1,
    permite_pago_total  TINYINT(1) NOT NULL DEFAULT 1,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (servicio_id),
    CONSTRAINT fk_config_pago_servicio FOREIGN KEY (servicio_id)
        REFERENCES servicios (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO servicios_config_pago
    (servicio_id, tipo_garantia, valor_garantia, permite_anticipo, permite_pago_total)
SELECT
    s.id,
    IF(s.monto_anticipo > 0, 'fijo', 'ninguna'),
    s.monto_anticipo,
    1,
    1
FROM servicios s
LEFT JOIN servicios_config_pago c ON c.servicio_id = s.id
WHERE c.servicio_id IS NULL;

CREATE TABLE IF NOT EXISTS citas_control (
    cita_id                    INT UNSIGNED NOT NULL,
    modalidad_pago             ENUM('sin_anticipo','anticipo','total') NOT NULL DEFAULT 'anticipo',
    precio_total               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    garantia                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monto_pagado               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    comision_porcentaje        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    estado_flujo               VARCHAR(40) NOT NULL DEFAULT 'reservada',
    cliente_codigo             CHAR(6) DEFAULT NULL,
    negocio_codigo             CHAR(6) DEFAULT NULL,
    cliente_validado_at        DATETIME DEFAULT NULL,
    negocio_validado_at        DATETIME DEFAULT NULL,
    prorroga_minutos           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    prorroga_estado            ENUM('ninguna','solicitada','aceptada','rechazada') NOT NULL DEFAULT 'ninguna',
    prorroga_solicitada_por    INT UNSIGNED DEFAULT NULL,
    final_codigo               CHAR(6) DEFAULT NULL,
    final_solicitada_at        DATETIME DEFAULT NULL,
    final_confirmada_at        DATETIME DEFAULT NULL,
    final_respuesta_limite     DATETIME DEFAULT NULL,
    liberar_at                 DATETIME DEFAULT NULL,
    liberado_at                DATETIME DEFAULT NULL,
    created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cita_id),
    KEY idx_control_estado (estado_flujo),
    KEY idx_control_liberar (liberar_at, liberado_at),
    CONSTRAINT fk_control_cita FOREIGN KEY (cita_id)
        REFERENCES citas (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_control_prorroga_usuario FOREIGN KEY (prorroga_solicitada_por)
        REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO citas_control
    (cita_id, modalidad_pago, precio_total, garantia, monto_pagado, estado_flujo)
SELECT
    c.id,
    IF(c.monto_anticipo > 0, 'anticipo', 'sin_anticipo'),
    s.precio_estimado,
    c.monto_anticipo,
    c.monto_anticipo,
    CASE c.estado
        WHEN 'en_curso' THEN 'en_curso'
        WHEN 'completada' THEN 'liberada'
        WHEN 'no_show' THEN 'no_show_resuelto'
        WHEN 'cancelada_tiempo' THEN 'cancelada'
        WHEN 'cancelada_tarde' THEN 'no_show_resuelto'
        ELSE 'reservada'
    END
FROM citas c
JOIN servicios s ON s.id = c.servicio_id
LEFT JOIN citas_control cc ON cc.cita_id = c.id
WHERE cc.cita_id IS NULL;

CREATE TABLE IF NOT EXISTS citas_disputas (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cita_id             INT UNSIGNED NOT NULL,
    abierta_por         INT UNSIGNED NOT NULL,
    motivo              VARCHAR(80) NOT NULL,
    descripcion         VARCHAR(2000) NOT NULL,
    evidencia_texto     VARCHAR(2000) DEFAULT NULL,
    estado              ENUM('abierta','en_revision','resuelta') NOT NULL DEFAULT 'abierta',
    resolucion          ENUM('pendiente','reembolso_total','reembolso_parcial','pago_negocio','dividido') NOT NULL DEFAULT 'pendiente',
    monto_reembolso     DECIMAL(10,2) DEFAULT NULL,
    evidencias_hasta    DATETIME NOT NULL,
    resolver_hasta      DATETIME NOT NULL,
    resuelta_at         DATETIME DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disputa_activa (cita_id, estado),
    KEY idx_disputa_estado (estado, resolver_hasta),
    CONSTRAINT fk_disputa_cita FOREIGN KEY (cita_id)
        REFERENCES citas (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_disputa_usuario FOREIGN KEY (abierta_por)
        REFERENCES usuarios (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
