# Análisis de mejoras del proyecto

## Estado actual

Mova ya cubre el recorrido principal de cliente y comerciante: autenticación, búsqueda por cercanía, catálogo, apartados, pagos simulados, agenda con anticipo, panel del negocio, portal del cliente y carga de imágenes. El conjunto ampliado de demostración permite revisar estos flujos con una portada representativa de distintos giros.

## Prioridad alta: preparación para producción

1. **Manejo global de errores de API.** Registrar excepciones en el servidor y convertirlas siempre en JSON con un código HTTP correcto, sin mostrar trazas internas. Esto evita respuestas HTML con estado 200 ante fallos inesperados.
2. **Configuración fuera del código.** Mover host, usuario y contraseña de MySQL a variables de entorno o a un archivo local fuera del repositorio.
3. **Protección CSRF.** Incorporar un token de sesión en todas las operaciones que cambian inventario, dinero, citas o datos del negocio.
4. **Pagos reales e idempotentes.** Sustituir el simulador por un proveedor real con firma de webhooks, clave de idempotencia y conciliación de movimientos.
5. **Procesos programados.** Ejecutar expiración de apartados y detección de `no_show` mediante una tarea cron, además de la verificación oportunista actual.
6. **Pruebas automatizadas.** Cubrir concurrencia de inventario, doble pago, traslapes de citas, cancelaciones y permisos por rol con pruebas de integración.

## Prioridad media: experiencia y operación

1. **Horarios configurables por negocio.** Reemplazar el horario fijo por días laborables, descansos, feriados y bloqueos manuales.
2. **Gestión visual de identidad.** Añadir carga de logotipo y portada del negocio, reutilizando la validación segura empleada para productos.
3. **Rutas portables.** Eliminar rutas absolutas que contienen `/hackathon/` para permitir instalar el proyecto en cualquier subcarpeta o dominio.
4. **Accesibilidad de modales.** Añadir control de foco, cierre con `Escape`, restauración del foco y anuncios para lectores de pantalla.
5. **Estados de carga y reintento.** Diferenciar claramente red desconectada, sesión vencida, error de validación y error interno.
6. **Panel con filtros.** Incorporar búsqueda, filtro por estado, rango de fechas y paginación para citas y apartados.

## Prioridad de escala y mantenimiento

1. **Búsqueda indexada.** Aprovechar los índices `FULLTEXT` ya presentes en productos y servicios cuando el volumen crezca, manteniendo `LIKE` como alternativa para consultas cortas.
2. **Paginación consistente.** Aplicar límites y cursores a catálogos, citas, apartados y movimientos, no solo al buscador principal.
3. **Observabilidad.** Registrar errores, tiempos de respuesta, operaciones financieras e intentos fallidos de autenticación con identificadores de correlación.
4. **Política de archivos.** Añadir limpieza de imágenes reemplazadas o huérfanas, cuotas por negocio y generación de miniaturas.
5. **Versionado de base de datos.** Separar la creación inicial de cambios incrementales mediante migraciones SQL numeradas.

## Orden recomendado para la siguiente etapa

La siguiente etapa debería enfocarse primero en seguridad y confiabilidad: manejo global de errores, variables de entorno, CSRF, cron y pruebas de integración. Después conviene abordar horarios configurables, identidad visual y filtros de operación. La integración de pagos reales debe realizarse cuando esas garantías estén cubiertas.

## Datos de demostración

`sql/demo_tiendas.sql` agrega ocho tiendas, dieciséis productos y nueve servicios sin comida preparada. El script usa transacción y comprobaciones de existencia, por lo que se puede volver a ejecutar sin duplicar registros.
