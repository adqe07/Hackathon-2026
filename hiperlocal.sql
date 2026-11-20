-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 19-11-2026 a las 23:12:03
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `hiperlocal`
--
CREATE DATABASE IF NOT EXISTS `hiperlocal` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hiperlocal`;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `apartados`
--

CREATE TABLE `apartados` (
  `id` int(10) UNSIGNED NOT NULL,
  `producto_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `negocio_id` int(10) UNSIGNED NOT NULL COMMENT 'Desnormalización para consultas de panel',
  `cantidad` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `tipo_pago` enum('efectivo_tienda','online') NOT NULL,
  `estado` enum('pendiente','pagado','expirado','cancelado') NOT NULL DEFAULT 'pendiente',
  `precio_unitario` decimal(10,2) NOT NULL COMMENT 'Precio al momento del apartado (histórico)',
  `monto_total` decimal(10,2) NOT NULL,
  `fecha_expiracion` datetime NOT NULL COMMENT 'Para efectivo: now()+3h; para online: se actualiza al pagar',
  `notas_cliente` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Apartados de productos con TTL de expiración';

--
-- Volcado de datos para la tabla `apartados`
--

INSERT INTO `apartados` (`id`, `producto_id`, `cliente_id`, `negocio_id`, `cantidad`, `tipo_pago`, `estado`, `precio_unitario`, `monto_total`, `fecha_expiracion`, `notas_cliente`, `created_at`, `updated_at`) VALUES
(1, 2, 4, 1, 1, 'online', 'pagado', 189.00, 189.00, '2026-11-19 13:53:39', NULL, '2026-11-19 13:23:39', '2026-11-19 14:07:29'),
(2, 2, 4, 1, 1, 'online', 'pagado', 189.00, 189.00, '2026-11-19 15:10:59', NULL, '2026-11-19 14:40:59', '2026-11-19 14:40:59'),
(3, 2, 4, 1, 1, 'online', 'pagado', 189.00, 189.00, '2026-11-19 16:02:39', NULL, '2026-11-19 15:32:39', '2026-11-19 15:32:39');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `citas`
--

CREATE TABLE `citas` (
  `id` int(10) UNSIGNED NOT NULL,
  `servicio_id` int(10) UNSIGNED NOT NULL,
  `negocio_id` int(10) UNSIGNED NOT NULL COMMENT 'Desnormalización para agenda del negocio',
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `fecha_hora_inicio` datetime NOT NULL,
  `fecha_hora_fin` datetime NOT NULL COMMENT 'Calculado: inicio + duracion_minutos del servicio',
  `estado` enum('pendiente_pago','agendada','confirmada','en_curso','completada','cancelada_tiempo','cancelada_tarde','no_show') NOT NULL DEFAULT 'pendiente_pago',
  `monto_anticipo` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Anticipo cobrado al momento de agendar (histórico)',
  `monto_anticipo_retenido` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto final retenido/transferido por penalización',
  `pin_verificacion` char(4) DEFAULT NULL COMMENT 'PIN de 4 dígitos para iniciar servicio EN_CURSO',
  `fecha_limite_pago` datetime DEFAULT NULL COMMENT 'Expiración de ventana PENDIENTE_PAGO (now()+10 min)',
  `notas_cliente` varchar(500) DEFAULT NULL,
  `notas_negocio` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Citas de servicio con máquina de estados y anticipo escrow';

--
-- Volcado de datos para la tabla `citas`
--

INSERT INTO `citas` (`id`, `servicio_id`, `negocio_id`, `cliente_id`, `fecha_hora_inicio`, `fecha_hora_fin`, `estado`, `monto_anticipo`, `monto_anticipo_retenido`, `pin_verificacion`, `fecha_limite_pago`, `notas_cliente`, `notas_negocio`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 4, '2026-11-25 14:30:00', '2026-11-25 15:30:00', 'cancelada_tiempo', 80.00, 0.00, '7281', NULL, NULL, NULL, '2026-11-19 14:01:16', '2026-11-19 14:07:20'),
(2, 2, 1, 4, '2026-11-24 14:00:00', '2026-11-24 16:00:00', 'cancelada_tiempo', 200.00, 0.00, '7041', NULL, NULL, NULL, '2026-11-19 14:03:37', '2026-11-19 14:07:23'),
(3, 4, 3, 4, '2026-11-23 10:00:00', '2026-11-23 11:00:00', 'agendada', 150.00, 150.00, '9698', NULL, NULL, NULL, '2026-11-19 15:47:19', '2026-11-19 15:47:19');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `movimientos_ledger`
--

CREATE TABLE `movimientos_ledger` (
  `id` int(10) UNSIGNED NOT NULL,
  `cita_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL si el movimiento es por apartado online',
  `apartado_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL si el movimiento es por cita',
  `usuario_id` int(10) UNSIGNED NOT NULL COMMENT 'Usuario afectado (cliente o negocio)',
  `tipo` enum('retencion_anticipo','liberacion_negocio','reembolso_cliente','compensacion_negocio','pago_online','devolucion_online') NOT NULL,
  `monto` decimal(10,2) NOT NULL COMMENT 'Siempre positivo; el tipo indica la dirección',
  `descripcion` varchar(300) DEFAULT NULL,
  `referencia_pago` varchar(100) DEFAULT NULL COMMENT 'ID de transacción externo (Stripe/MercadoPago)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Libro mayor de auditoría financiera (anticipos, reembolsos, compensaciones)';

--
-- Volcado de datos para la tabla `movimientos_ledger`
--

INSERT INTO `movimientos_ledger` (`id`, `cita_id`, `apartado_id`, `usuario_id`, `tipo`, `monto`, `descripcion`, `referencia_pago`, `created_at`) VALUES
(1, 1, NULL, 4, 'retencion_anticipo', 80.00, 'Retención de anticipo en custodia (Escrow) para cita #1 de Corte y Peinado Profesional', NULL, '2026-11-19 14:01:16'),
(2, 2, NULL, 4, 'retencion_anticipo', 200.00, 'Retención de anticipo en custodia (Escrow) para cita #2 de Coloración Completa con Tinte', NULL, '2026-11-19 14:03:37'),
(3, 1, NULL, 4, 'reembolso_cliente', 80.00, 'Reembolso del 100% de anticipo por cancelación a tiempo de cita #1', NULL, '2026-11-19 14:07:20'),
(4, 2, NULL, 4, 'reembolso_cliente', 200.00, 'Reembolso del 100% de anticipo por cancelación a tiempo de cita #2', NULL, '2026-11-19 14:07:23'),
(5, NULL, 1, 2, 'pago_online', 189.00, 'Pago en línea completado (Click & Collect) para apartado #1', 'SIM-PAY-4155FB32', '2026-11-19 14:07:29'),
(6, NULL, 2, 2, 'pago_online', 189.00, 'Pago en línea completado (Click & Collect) para apartado #2', 'SIM-PAY-FECD6E00', '2026-11-19 14:40:59'),
(7, NULL, 3, 2, 'pago_online', 189.00, 'Pago en línea completado (Click & Collect) para apartado #3', 'SIM-PAY-0E645264', '2026-11-19 15:32:39'),
(8, 3, NULL, 4, 'retencion_anticipo', 150.00, 'Retención de anticipo en custodia (Escrow) para cita #3 de Masaje con final feliz', NULL, '2026-11-19 15:47:19');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `negocios`
--

CREATE TABLE `negocios` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `categoria` varchar(100) NOT NULL COMMENT 'ej: estetica, barberia, perfumeria, bazar, taller',
  `descripcion` text DEFAULT NULL,
  `telefono_contacto` varchar(20) DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `latitud` decimal(10,8) DEFAULT NULL COMMENT 'WGS84 - precisión ~1.1 mm',
  `longitud` decimal(11,8) DEFAULT NULL COMMENT 'WGS84 - precisión ~1.1 mm',
  `logo_url` varchar(500) DEFAULT NULL,
  `plan_suscripcion` enum('basico','pro') NOT NULL DEFAULT 'basico',
  `ventana_cancelacion_horas` tinyint(3) UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Horas antes de la cita para cancelar sin penalización',
  `verificado` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfil y configuración de cada negocio local';

--
-- Volcado de datos para la tabla `negocios`
--

INSERT INTO `negocios` (`id`, `usuario_id`, `nombre`, `categoria`, `descripcion`, `telefono_contacto`, `direccion`, `ciudad`, `latitud`, `longitud`, `logo_url`, `plan_suscripcion`, `ventana_cancelacion_horas`, `verificado`, `activo`, `created_at`, `updated_at`) VALUES
(1, 2, 'Estética Bella Imagen', 'estetica', 'Estética profesional con más de 10 años de experiencia. Ofrecemos corte, peinado, coloración, tratamientos capilares, manicure y pedicure. Trabajamos con productos de alta calidad. ¡Tu belleza es nuestra pasión!', '5511111111', 'Orizaba 101, Col. Roma Norte, Cuauhtémoc', 'Ciudad de México', 19.41941870, -99.16284600, NULL, 'pro', 24, 1, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(2, 3, 'Perfumería Essence Luxe', 'perfumeria', 'Boutique de fragancias nacionales e internacionales. Encontrarás perfumes originales, decants, velas aromáticas y accesorios de belleza. También realizamos envíos locales y apartados en línea.', '5522222222', 'Presidente Masaryk 123, Col. Polanco V Sección, Miguel Hidalgo', 'Ciudad de México', 19.43200000, -99.19250000, NULL, 'basico', 12, 0, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(3, 6, 'Farmacia bienmashinestar', 'otro', 'Farmacia con medecena bien mashin viejo', '6181895381', 'Rosal, La virgen 200', 'Durango', 24.03589853, -104.64574537, NULL, 'basico', 24, 0, 1, '2026-11-19 15:06:39', '2026-11-19 15:06:39'),
(4, 7, 'Barbería Distrito 46', 'barberia', 'Barbería de barrio con cortes clásicos y contemporáneos, arreglo de barba y asesoría de estilo. Atención con cita y productos profesionales para cuidado masculino.', '5510001001', 'Av. Tamaulipas 46, Col. Condesa, Cuauhtémoc', 'Ciudad de México', 19.41290000, -99.17620000, NULL, 'pro', 6, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(5, 8, 'Bazar Nómada Local', 'bazar', 'Selección de objetos hechos por creadores mexicanos: decoración, papelería, textiles y regalos útiles en ediciones pequeñas.', '5510001002', 'Liverpool 88, Col. Juárez, Cuauhtémoc', 'Ciudad de México', 19.42680000, -99.15870000, NULL, 'basico', 12, 0, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(6, 9, 'Boutique Lino y Luna', 'boutique', 'Prendas atemporales, accesorios y piezas de diseñadores independientes. Colecciones pequeñas con atención personalizada en tienda.', '5510001003', 'Tehuantepec 128, Col. Roma Sur, Cuauhtémoc', 'Ciudad de México', 19.40680000, -99.16450000, NULL, 'pro', 24, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(7, 10, 'Papel y Oficio', 'comercio', 'Papelería creativa y artículos de escritorio para estudio, oficina y proyectos personales. Productos funcionales de marcas mexicanas.', '5510001008', 'Donceles 92, Centro Histórico, Cuauhtémoc', 'Ciudad de México', 19.42700000, -99.14950000, NULL, 'basico', 12, 0, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(8, 11, 'Bruma Joyería Artesanal', 'joyeria', 'Joyería contemporánea elaborada en plata y latón por manos locales. Piezas de serie corta, ajustes y reparaciones en el taller.', '5510001007', 'Serapio Rendón 54, Col. San Rafael, Cuauhtémoc', 'Ciudad de México', 19.43780000, -99.16160000, NULL, 'pro', 24, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(9, 12, 'Armario Circular', 'ropa', 'Ropa seleccionada de segunda mano en excelente estado y prendas de producción local. Moda accesible con enfoque de consumo responsable.', '5510001006', 'Ámsterdam 215, Col. Hipódromo, Cuauhtémoc', 'Ciudad de México', 19.41040000, -99.17070000, NULL, 'basico', 24, 0, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(10, 13, 'Taller Ciclo Urbano', 'taller', 'Mantenimiento y reparación de bicicletas urbanas. Diagnóstico transparente, refacciones disponibles y servicio para trayectos cotidianos.', '5510001004', 'José Martí 211, Col. Escandón, Miguel Hidalgo', 'Ciudad de México', 19.40260000, -99.17370000, NULL, 'basico', 8, 0, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(11, 14, 'TecnoFix Del Valle', 'tecnico', 'Diagnóstico y reparación de celulares, tabletas y computadoras. Mantenimiento preventivo, respaldos y venta de accesorios compatibles.', '5510001005', 'Amores 742, Col. Del Valle Norte, Benito Juárez', 'Ciudad de México', 19.38680000, -99.16210000, NULL, 'pro', 12, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id` int(10) UNSIGNED NOT NULL,
  `negocio_id` int(10) UNSIGNED NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `stock_total` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Stock real en bodega',
  `stock_disponible` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Stock real menos unidades en apartado activo',
  `imagen_url` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inventario de productos por negocio';

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id`, `negocio_id`, `titulo`, `descripcion`, `categoria`, `precio`, `stock_total`, `stock_disponible`, `imagen_url`, `activo`, `created_at`, `updated_at`) VALUES
(1, 1, 'Kit Tratamiento Hidratante Capilar', 'Set completo con shampoo, acondicionador y mascarilla hidratante profesional. Ideal para cabello seco y dañado. Rinde hasta 30 aplicaciones. Marca: HydraGloss Pro.', 'cuidado_capilar', 349.00, 15, 15, NULL, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(2, 1, 'Tinte Semipermanente L\'Oréal - Rubio Dorado #7.3', 'Tinte semipermanente sin amoniaco, cobertura hasta 70% de canas. Duración aproximada 6-8 semanas. Tono: Rubio Dorado 7.3. Incluye revelador 20 vol.', 'tintes', 189.00, 27, 27, NULL, 1, '2026-11-19 12:37:59', '2026-11-19 15:32:39'),
(3, 2, 'Perfume Dior Sauvage EDT 100ml (Original)', 'Eau de Toilette masculino Dior Sauvage 100ml. Notas de bergamota, ambroxan y pimienta. Producto 100% original con garantía de autenticidad. Incluye caja sellada.', 'perfumes_hombre', 1850.00, 8, 8, NULL, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(4, 2, 'Set Velas Aromáticas Artesanales (3 piezas)', 'Set de 3 velas de soya artesanales en aromas: Vainilla & Sándalo, Lavanda & Menta, Bergamota & Cedro. 150g cada una. Tiempo de quema: ~40 horas por vela.', 'aromaterapia', 320.00, 20, 20, NULL, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(5, 3, 'Mota bienmashinestar', 'La mota mas mashin viejo la neta', 'general', 50.00, 10, 10, 'uploads/productos/prod_6aff6553109eb4.78127739.webp', 1, '2026-11-19 15:08:35', '2026-11-19 15:08:35'),
(6, 4, 'Aceite nutritivo para barba', 'Mezcla ligera de jojoba y argán para suavizar barba y piel sin sensación grasosa.', 'cuidado_masculino', 289.00, 12, 12, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(7, 4, 'Pomada mate de fijación media', 'Pomada base agua para acabado natural, fácil de retirar y adecuada para uso diario.', 'cuidado_masculino', 249.00, 18, 18, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(8, 5, 'Libreta cosida de puntos', 'Libreta de 120 páginas en papel reciclado, ideal para notas y bullet journal.', 'papeleria', 160.00, 24, 24, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(9, 5, 'Maceta de cerámica esmaltada', 'Maceta artesanal de 14 cm con plato, producida en pequeño taller mexicano.', 'decoracion', 220.00, 9, 9, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(10, 6, 'Blusa de lino natural', 'Blusa fresca de corte relajado, disponible en tallas chica, mediana y grande.', 'ropa_mujer', 690.00, 8, 8, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(11, 6, 'Bolso tejido de hombro', 'Bolso de fibras naturales con forro interior y bolsillo con cierre.', 'accesorios', 820.00, 5, 5, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(12, 7, 'Cuaderno de pasta rígida', 'Cuaderno tamaño media carta con 160 páginas y apertura plana.', 'papeleria', 145.00, 28, 28, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(13, 7, 'Organizador modular de escritorio', 'Set de tres módulos apilables para lápices, notas y accesorios pequeños.', 'oficina', 280.00, 11, 11, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(14, 8, 'Aretes de plata textura lunar', 'Aretes ligeros de plata .925 con acabado martillado, hechos a mano.', 'aretes', 540.00, 7, 7, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(15, 8, 'Collar órbita de latón', 'Collar geométrico de latón pulido con cadena ajustable de 45 a 50 cm.', 'collares', 890.00, 6, 6, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(16, 9, 'Chamarra de mezclilla restaurada', 'Chamarra unisex de segunda mano, revisada y restaurada. Pieza única talla mediana.', 'ropa_unisex', 780.00, 1, 1, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(17, 9, 'Vestido midi estampado', 'Vestido ligero de producción local con corte midi y bolsillos laterales.', 'ropa_mujer', 620.00, 4, 4, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(18, 10, 'Candado tipo U reforzado', 'Candado de acero endurecido con soporte para cuadro y dos llaves.', 'seguridad_bicicleta', 450.00, 10, 10, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(19, 10, 'Juego de luces recargables', 'Luces LED delantera y trasera con carga USB y tres modos de iluminación.', 'accesorios_bicicleta', 310.00, 16, 16, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(20, 11, 'Cable USB-C reforzado 2 m', 'Cable de carga y datos con recubrimiento trenzado y conectores reforzados.', 'accesorios_tecnologia', 180.00, 32, 32, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(21, 11, 'Protector universal para tableta', 'Funda ajustable con soporte para tabletas de 9 a 11 pulgadas.', 'accesorios_tecnologia', 320.00, 14, 14, NULL, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `servicios`
--

CREATE TABLE `servicios` (
  `id` int(10) UNSIGNED NOT NULL,
  `negocio_id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `duracion_minutos` smallint(5) UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Duración del servicio para bloquear horario',
  `precio_estimado` decimal(10,2) NOT NULL DEFAULT 0.00,
  `monto_anticipo` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto fijo requerido como anticipo para agendar',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Servicios disponibles para agendar cita';

--
-- Volcado de datos para la tabla `servicios`
--

INSERT INTO `servicios` (`id`, `negocio_id`, `nombre`, `descripcion`, `duracion_minutos`, `precio_estimado`, `monto_anticipo`, `activo`, `created_at`, `updated_at`) VALUES
(1, 1, 'Corte y Peinado Profesional', 'Incluye lavado con productos premium, corte personalizado según tipo de cara, secado y peinado. Para todo tipo de cabello. Duración aproximada 60 minutos.', 60, 280.00, 80.00, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(2, 1, 'Coloración Completa con Tinte', 'Aplicación de tinte en raíz y puntas, incluye revelador. Se realiza consulta de color previa. Puede incluir mechas o balayage con costo adicional. Duración aproximada 120 minutos.', 120, 650.00, 200.00, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(3, 1, 'Manicure Semipermanente', 'Preparación de uñas, aplicación de base, color semipermanente y top coat con lámpara UV. Incluye diseño básico. Dura entre 2 y 3 semanas sin levantamiento.', 75, 220.00, 60.00, 1, '2026-11-19 12:37:59', '2026-11-19 12:37:59'),
(4, 3, 'Masaje con final feliz', 'Masaje bien mashin con un final bien vergas la neta viejo', 60, 1500.00, 150.00, 1, '2026-11-19 15:10:08', '2026-11-19 15:10:08'),
(5, 4, 'Corte personalizado', 'Consulta breve, corte con máquina y tijera, lavado y peinado final.', 45, 250.00, 80.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(6, 4, 'Perfilado de barba', 'Diseño, recorte, toalla caliente y aplicación de aceite nutritivo.', 30, 180.00, 60.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(7, 6, 'Asesoría de imagen en tienda', 'Sesión individual para definir combinaciones, cortes y accesorios según estilo personal.', 60, 450.00, 150.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(8, 7, 'Personalización de libreta', 'Grabado de nombre o frase corta en una libreta adquirida en tienda.', 30, 180.00, 60.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(9, 8, 'Ajuste y reparación de joyería', 'Evaluación, limpieza y reparación básica de piezas de plata, latón o chapa.', 45, 250.00, 80.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(10, 10, 'Diagnóstico y ajuste rápido', 'Inspección de seguridad y ajustes menores para uso urbano cotidiano.', 30, 180.00, 50.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(11, 10, 'Servicio completo de bicicleta', 'Ajuste de frenos y cambios, lubricación, revisión de ruedas y apriete general.', 90, 520.00, 150.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(12, 11, 'Diagnóstico de celular', 'Evaluación de hardware y software con presupuesto antes de realizar la reparación.', 30, 350.00, 100.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(13, 11, 'Mantenimiento de laptop', 'Limpieza interna, cambio de pasta térmica y revisión de rendimiento.', 90, 650.00, 200.00, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(180) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `rol` enum('cliente','negocio','admin') NOT NULL DEFAULT 'cliente',
  `saldo_monedero` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo en MXN acumulado por reembolsos de anticipos',
  `avatar_url` varchar(500) DEFAULT NULL,
  `email_verificado` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clientes y dueños de negocio';

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password_hash`, `telefono`, `rol`, `saldo_monedero`, `avatar_url`, `email_verificado`, `activo`, `created_at`, `updated_at`) VALUES
(1, 'Admin Sistema', 'admin@hiperlocal.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5500000001', 'admin', 0.00, NULL, 0, 1, '2026-11-19 12:37:59', '2026-11-19 13:23:12'),
(2, 'Dueña Estética', 'estetica@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5511111111', 'negocio', 567.00, NULL, 0, 1, '2026-11-19 12:37:59', '2026-11-19 15:32:39'),
(3, 'Dueña Perfumería', 'perfumeria@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5522222222', 'negocio', 0.00, NULL, 0, 1, '2026-11-19 12:37:59', '2026-11-19 13:23:12'),
(4, 'Ana García', 'ana@cliente.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5533333333', 'cliente', 1430.00, NULL, 0, 1, '2026-11-19 12:37:59', '2026-11-19 14:07:49'),
(5, 'Luis Martínez', 'luis@cliente.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5544444444', 'cliente', 0.00, NULL, 0, 1, '2026-11-19 12:37:59', '2026-11-19 13:23:12'),
(6, 'Jezer Diaz Santos', 'f4k3alien@gmail.com', '$2y$12$0DYUmQFoED17MgOC.5IWQO2amNU8X.u8kzSGJI.IXfMmd0uZMYV3e', NULL, 'negocio', 0.00, NULL, 0, 1, '2026-11-19 12:51:20', '2026-11-19 12:51:20'),
(7, 'Marco Reyes', 'barberia@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001001', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(8, 'Sofía Navarro', 'bazar@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001002', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(9, 'Elena Campos', 'boutique@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001003', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(10, 'Tomás Vega', 'comercio@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001008', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(11, 'Renata Mora', 'joyeria@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001007', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(12, 'Valeria Luna', 'ropa@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001006', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(13, 'Diego Salas', 'taller@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001004', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17'),
(14, 'Irene Paredes', 'tecnico@demo.mx', '$2y$12$4Yi2e.D.B3WgW//Pg2q9h.oo.fbA253rXz4bHgYHPfoLrVotw6p16', '5510001005', 'negocio', 0.00, NULL, 1, 1, '2026-11-19 15:38:17', '2026-11-19 15:38:17');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `apartados`
--
ALTER TABLE `apartados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_apartados_producto` (`producto_id`),
  ADD KEY `idx_apartados_cliente` (`cliente_id`),
  ADD KEY `idx_apartados_negocio` (`negocio_id`),
  ADD KEY `idx_apartados_estado` (`estado`),
  ADD KEY `idx_apartados_expiracion` (`fecha_expiracion`);

--
-- Indices de la tabla `citas`
--
ALTER TABLE `citas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_citas_servicio` (`servicio_id`),
  ADD KEY `idx_citas_negocio` (`negocio_id`),
  ADD KEY `idx_citas_cliente` (`cliente_id`),
  ADD KEY `idx_citas_estado` (`estado`),
  ADD KEY `idx_citas_fecha_inicio` (`fecha_hora_inicio`),
  ADD KEY `idx_citas_negocio_fecha` (`negocio_id`,`fecha_hora_inicio`,`fecha_hora_fin`);

--
-- Indices de la tabla `movimientos_ledger`
--
ALTER TABLE `movimientos_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ledger_cita` (`cita_id`),
  ADD KEY `idx_ledger_apartado` (`apartado_id`),
  ADD KEY `idx_ledger_usuario` (`usuario_id`),
  ADD KEY `idx_ledger_tipo` (`tipo`),
  ADD KEY `idx_ledger_created_at` (`created_at`);

--
-- Indices de la tabla `negocios`
--
ALTER TABLE `negocios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_negocios_usuario` (`usuario_id`),
  ADD KEY `idx_negocios_categoria` (`categoria`),
  ADD KEY `idx_negocios_plan` (`plan_suscripcion`),
  ADD KEY `idx_negocios_coords` (`latitud`,`longitud`),
  ADD KEY `idx_negocios_activo` (`activo`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_productos_negocio` (`negocio_id`),
  ADD KEY `idx_productos_activo` (`activo`),
  ADD KEY `idx_productos_stock` (`stock_disponible`);
ALTER TABLE `productos` ADD FULLTEXT KEY `ft_productos_titulo_desc` (`titulo`,`descripcion`);

--
-- Indices de la tabla `servicios`
--
ALTER TABLE `servicios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_servicios_negocio` (`negocio_id`),
  ADD KEY `idx_servicios_activo` (`activo`);
ALTER TABLE `servicios` ADD FULLTEXT KEY `ft_servicios_nombre_desc` (`nombre`,`descripcion`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuarios_email` (`email`),
  ADD KEY `idx_usuarios_rol` (`rol`),
  ADD KEY `idx_usuarios_activo` (`activo`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `apartados`
--
ALTER TABLE `apartados`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `citas`
--
ALTER TABLE `citas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `movimientos_ledger`
--
ALTER TABLE `movimientos_ledger`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `negocios`
--
ALTER TABLE `negocios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT de la tabla `servicios`
--
ALTER TABLE `servicios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `apartados`
--
ALTER TABLE `apartados`
  ADD CONSTRAINT `fk_apartados_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_apartados_negocio` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_apartados_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `citas`
--
ALTER TABLE `citas`
  ADD CONSTRAINT `fk_citas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_citas_negocio` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_citas_servicio` FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `movimientos_ledger`
--
ALTER TABLE `movimientos_ledger`
  ADD CONSTRAINT `fk_ledger_apartado` FOREIGN KEY (`apartado_id`) REFERENCES `apartados` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ledger_cita` FOREIGN KEY (`cita_id`) REFERENCES `citas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ledger_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `negocios`
--
ALTER TABLE `negocios`
  ADD CONSTRAINT `fk_negocios_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `fk_productos_negocio` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `servicios`
--
ALTER TABLE `servicios`
  ADD CONSTRAINT `fk_servicios_negocio` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
