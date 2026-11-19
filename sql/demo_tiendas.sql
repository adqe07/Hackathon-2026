-- =============================================================================
-- DATOS DEMO AMPLIADOS: TIENDAS, PRODUCTOS Y SERVICIOS
-- Plataforma Mova | Catálogo visual para desarrollo y demostraciones
-- =============================================================================
-- Script idempotente: puede ejecutarse varias veces sin duplicar registros.
-- Contraseña de todas las cuentas demo: Demo1234!

USE mova;
SET NAMES utf8mb4;

START TRANSACTION;

CREATE TEMPORARY TABLE demo_usuarios (
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    PRIMARY KEY (email)
);

INSERT INTO demo_usuarios (nombre, email, telefono) VALUES
('Marco Reyes', 'barberia@demo.mx', '5510001001'),
('Sofía Navarro', 'bazar@demo.mx', '5510001002'),
('Elena Campos', 'boutique@demo.mx', '5510001003'),
('Diego Salas', 'taller@demo.mx', '5510001004'),
('Irene Paredes', 'tecnico@demo.mx', '5510001005'),
('Valeria Luna', 'ropa@demo.mx', '5510001006'),
('Renata Mora', 'joyeria@demo.mx', '5510001007'),
('Tomás Vega', 'comercio@demo.mx', '5510001008');

INSERT INTO usuarios
    (nombre, email, password_hash, telefono, rol, saldo_monedero, email_verificado, activo)
SELECT
    d.nombre,
    d.email,
    '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16',
    d.telefono,
    'negocio',
    0.00,
    1,
    1
FROM demo_usuarios d
LEFT JOIN usuarios u ON u.email = d.email
WHERE u.id IS NULL;

CREATE TEMPORARY TABLE demo_negocios (
    email VARCHAR(180) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    descripcion TEXT NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    direccion VARCHAR(300) NOT NULL,
    ciudad VARCHAR(100) NOT NULL,
    latitud DECIMAL(10,8) NOT NULL,
    longitud DECIMAL(11,8) NOT NULL,
    plan ENUM('basico','pro') NOT NULL,
    ventana TINYINT UNSIGNED NOT NULL,
    verificado TINYINT(1) NOT NULL,
    PRIMARY KEY (email)
);

INSERT INTO demo_negocios
    (email, nombre, categoria, descripcion, telefono, direccion, ciudad,
     latitud, longitud, plan, ventana, verificado)
VALUES
('barberia@demo.mx', 'Barbería Distrito 46', 'barberia',
 'Barbería de barrio con cortes clásicos y contemporáneos, arreglo de barba y asesoría de estilo. Atención con cita y productos profesionales para cuidado masculino.',
 '5510001001', 'Av. Tamaulipas 46, Col. Condesa, Cuauhtémoc', 'Ciudad de México',
 19.41290000, -99.17620000, 'pro', 6, 1),
('bazar@demo.mx', 'Bazar Nómada Local', 'bazar',
 'Selección de objetos hechos por creadores mexicanos: decoración, papelería, textiles y regalos útiles en ediciones pequeñas.',
 '5510001002', 'Liverpool 88, Col. Juárez, Cuauhtémoc', 'Ciudad de México',
 19.42680000, -99.15870000, 'basico', 12, 0),
('boutique@demo.mx', 'Boutique Lino y Luna', 'boutique',
 'Prendas atemporales, accesorios y piezas de diseñadores independientes. Colecciones pequeñas con atención personalizada en tienda.',
 '5510001003', 'Tehuantepec 128, Col. Roma Sur, Cuauhtémoc', 'Ciudad de México',
 19.40680000, -99.16450000, 'pro', 24, 1),
('taller@demo.mx', 'Taller Ciclo Urbano', 'taller',
 'Mantenimiento y reparación de bicicletas urbanas. Diagnóstico transparente, refacciones disponibles y servicio para trayectos cotidianos.',
 '5510001004', 'José Martí 211, Col. Escandón, Miguel Hidalgo', 'Ciudad de México',
 19.40260000, -99.17370000, 'basico', 8, 0),
('tecnico@demo.mx', 'TecnoFix Del Valle', 'tecnico',
 'Diagnóstico y reparación de celulares, tabletas y computadoras. Mantenimiento preventivo, respaldos y venta de accesorios compatibles.',
 '5510001005', 'Amores 742, Col. Del Valle Norte, Benito Juárez', 'Ciudad de México',
 19.38680000, -99.16210000, 'pro', 12, 1),
('ropa@demo.mx', 'Armario Circular', 'ropa',
 'Ropa seleccionada de segunda mano en excelente estado y prendas de producción local. Moda accesible con enfoque de consumo responsable.',
 '5510001006', 'Ámsterdam 215, Col. Hipódromo, Cuauhtémoc', 'Ciudad de México',
 19.41040000, -99.17070000, 'basico', 24, 0),
('joyeria@demo.mx', 'Bruma Joyería Artesanal', 'joyeria',
 'Joyería contemporánea elaborada en plata y latón por manos locales. Piezas de serie corta, ajustes y reparaciones en el taller.',
 '5510001007', 'Serapio Rendón 54, Col. San Rafael, Cuauhtémoc', 'Ciudad de México',
 19.43780000, -99.16160000, 'pro', 24, 1),
('comercio@demo.mx', 'Papel y Oficio', 'comercio',
 'Papelería creativa y artículos de escritorio para estudio, oficina y proyectos personales. Productos funcionales de marcas mexicanas.',
 '5510001008', 'Donceles 92, Centro Histórico, Cuauhtémoc', 'Ciudad de México',
 19.42700000, -99.14950000, 'basico', 12, 0);

INSERT INTO negocios
    (usuario_id, nombre, categoria, descripcion, telefono_contacto, direccion, ciudad,
     latitud, longitud, plan_suscripcion, ventana_cancelacion_horas, verificado, activo)
SELECT
    u.id, d.nombre, d.categoria, d.descripcion, d.telefono, d.direccion, d.ciudad,
    d.latitud, d.longitud, d.plan, d.ventana, d.verificado, 1
FROM demo_negocios d
INNER JOIN usuarios u ON u.email = d.email
LEFT JOIN negocios n ON n.usuario_id = u.id
WHERE n.id IS NULL;

CREATE TEMPORARY TABLE demo_productos (
    negocio VARCHAR(150) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    stock INT UNSIGNED NOT NULL,
    PRIMARY KEY (negocio, titulo)
);

INSERT INTO demo_productos
    (negocio, titulo, descripcion, categoria, precio, stock)
VALUES
('Barbería Distrito 46', 'Pomada mate de fijación media', 'Pomada base agua para acabado natural, fácil de retirar y adecuada para uso diario.', 'cuidado_masculino', 249.00, 18),
('Barbería Distrito 46', 'Aceite nutritivo para barba', 'Mezcla ligera de jojoba y argán para suavizar barba y piel sin sensación grasosa.', 'cuidado_masculino', 289.00, 12),
('Bazar Nómada Local', 'Maceta de cerámica esmaltada', 'Maceta artesanal de 14 cm con plato, producida en pequeño taller mexicano.', 'decoracion', 220.00, 9),
('Bazar Nómada Local', 'Libreta cosida de puntos', 'Libreta de 120 páginas en papel reciclado, ideal para notas y bullet journal.', 'papeleria', 160.00, 24),
('Boutique Lino y Luna', 'Blusa de lino natural', 'Blusa fresca de corte relajado, disponible en tallas chica, mediana y grande.', 'ropa_mujer', 690.00, 8),
('Boutique Lino y Luna', 'Bolso tejido de hombro', 'Bolso de fibras naturales con forro interior y bolsillo con cierre.', 'accesorios', 820.00, 5),
('Taller Ciclo Urbano', 'Juego de luces recargables', 'Luces LED delantera y trasera con carga USB y tres modos de iluminación.', 'accesorios_bicicleta', 310.00, 16),
('Taller Ciclo Urbano', 'Candado tipo U reforzado', 'Candado de acero endurecido con soporte para cuadro y dos llaves.', 'seguridad_bicicleta', 450.00, 10),
('TecnoFix Del Valle', 'Cable USB-C reforzado 2 m', 'Cable de carga y datos con recubrimiento trenzado y conectores reforzados.', 'accesorios_tecnologia', 180.00, 32),
('TecnoFix Del Valle', 'Protector universal para tableta', 'Funda ajustable con soporte para tabletas de 9 a 11 pulgadas.', 'accesorios_tecnologia', 320.00, 14),
('Armario Circular', 'Chamarra de mezclilla restaurada', 'Chamarra unisex de segunda mano, revisada y restaurada. Pieza única talla mediana.', 'ropa_unisex', 780.00, 1),
('Armario Circular', 'Vestido midi estampado', 'Vestido ligero de producción local con corte midi y bolsillos laterales.', 'ropa_mujer', 620.00, 4),
('Bruma Joyería Artesanal', 'Aretes de plata textura lunar', 'Aretes ligeros de plata .925 con acabado martillado, hechos a mano.', 'aretes', 540.00, 7),
('Bruma Joyería Artesanal', 'Collar órbita de latón', 'Collar geométrico de latón pulido con cadena ajustable de 45 a 50 cm.', 'collares', 890.00, 6),
('Papel y Oficio', 'Cuaderno de pasta rígida', 'Cuaderno tamaño media carta con 160 páginas y apertura plana.', 'papeleria', 145.00, 28),
('Papel y Oficio', 'Organizador modular de escritorio', 'Set de tres módulos apilables para lápices, notas y accesorios pequeños.', 'oficina', 280.00, 11);

INSERT INTO productos
    (negocio_id, titulo, descripcion, categoria, precio, stock_total, stock_disponible, activo)
SELECT
    n.id, d.titulo, d.descripcion, d.categoria, d.precio, d.stock, d.stock, 1
FROM demo_productos d
INNER JOIN negocios n ON n.nombre = d.negocio
LEFT JOIN productos p ON p.negocio_id = n.id AND p.titulo = d.titulo
WHERE p.id IS NULL;

CREATE TEMPORARY TABLE demo_servicios (
    negocio VARCHAR(150) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NOT NULL,
    duracion SMALLINT UNSIGNED NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    anticipo DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (negocio, nombre)
);

INSERT INTO demo_servicios
    (negocio, nombre, descripcion, duracion, precio, anticipo)
VALUES
('Barbería Distrito 46', 'Corte personalizado', 'Consulta breve, corte con máquina y tijera, lavado y peinado final.', 45, 250.00, 80.00),
('Barbería Distrito 46', 'Perfilado de barba', 'Diseño, recorte, toalla caliente y aplicación de aceite nutritivo.', 30, 180.00, 60.00),
('Boutique Lino y Luna', 'Asesoría de imagen en tienda', 'Sesión individual para definir combinaciones, cortes y accesorios según estilo personal.', 60, 450.00, 150.00),
('Taller Ciclo Urbano', 'Servicio completo de bicicleta', 'Ajuste de frenos y cambios, lubricación, revisión de ruedas y apriete general.', 90, 520.00, 150.00),
('Taller Ciclo Urbano', 'Diagnóstico y ajuste rápido', 'Inspección de seguridad y ajustes menores para uso urbano cotidiano.', 30, 180.00, 50.00),
('TecnoFix Del Valle', 'Diagnóstico de celular', 'Evaluación de hardware y software con presupuesto antes de realizar la reparación.', 30, 350.00, 100.00),
('TecnoFix Del Valle', 'Mantenimiento de laptop', 'Limpieza interna, cambio de pasta térmica y revisión de rendimiento.', 90, 650.00, 200.00),
('Bruma Joyería Artesanal', 'Ajuste y reparación de joyería', 'Evaluación, limpieza y reparación básica de piezas de plata, latón o chapa.', 45, 250.00, 80.00),
('Papel y Oficio', 'Personalización de libreta', 'Grabado de nombre o frase corta en una libreta adquirida en tienda.', 30, 180.00, 60.00);

INSERT INTO servicios
    (negocio_id, nombre, descripcion, duracion_minutos, precio_estimado, monto_anticipo, activo)
SELECT
    n.id, d.nombre, d.descripcion, d.duracion, d.precio, d.anticipo, 1
FROM demo_servicios d
INNER JOIN negocios n ON n.nombre = d.negocio
LEFT JOIN servicios s ON s.negocio_id = n.id AND s.nombre = d.nombre
WHERE s.id IS NULL;

DROP TEMPORARY TABLE demo_servicios;
DROP TEMPORARY TABLE demo_productos;
DROP TEMPORARY TABLE demo_negocios;
DROP TEMPORARY TABLE demo_usuarios;

COMMIT;

-- Resultado esperado al sumar los datos base de schema.sql:
-- 10 negocios semilla, 20 productos y 12 servicios.
