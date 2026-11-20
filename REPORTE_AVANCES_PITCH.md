# Reporte de avances para pitch — Mova

**Fecha de corte:** 19 de noviembre de 2026  
**Estado:** prototipo funcional de punta a punta para demostración local  
**Etapas concluidas:** 1 a 5

---

## 1. Resumen ejecutivo

Mova es una plataforma web de comercio y servicios cercanos creada para pequeños negocios, comerciantes, emprendedores y prestadores de servicios que hoy dependen de Facebook, Instagram o WhatsApp para conseguir clientes y organizar ventas o citas.

La plataforma permite que una persona encuentre negocios por cercanía, consulte sus productos y servicios, aparte artículos para recoger en tienda, pague en línea de forma simulada y reserve citas con anticipo. Al mismo tiempo, el comerciante cuenta con un panel para administrar su catálogo, inventario, servicios, agenda, citas y apartados.

Mova no compite con aplicaciones de entrega de comida. Su enfoque son los productos físicos y los servicios locales. No existe reparto a domicilio: los productos se recogen directamente en el establecimiento y los servicios se realizan en el negocio o bajo el acuerdo del prestador.

Actualmente existe un prototipo funcional con buscador por ubicación, perfiles públicos, autenticación por roles, catálogo, inventario, apartados con expiración, pagos simulados, agenda sin traslapes, anticipos, monedero digital, panel del comerciante y portal del cliente.

---

## 2. Problema que busca resolver

Muchos comercios locales tienen una oferta valiosa, pero enfrentan problemas recurrentes:

- Sus publicaciones pierden visibilidad por los algoritmos de redes sociales.
- Los clientes no pueden descubrir fácilmente qué negocios existen cerca de ellos.
- Los pedidos, apartados y citas se administran manualmente por mensajes.
- El comerciante pierde tiempo respondiendo disponibilidad y confirmando horarios.
- Las cancelaciones tardías generan espacios vacíos y pérdida de ingresos.
- Los negocios pequeños no siempre pueden pagar una tienda en línea o un sistema de agenda independiente.
- El comprador no tiene una experiencia unificada para consultar productos, apartar, pagar o reservar.

Mova reúne estas funciones en una sola plataforma ligera y enfocada en el comercio de proximidad.

---

## 3. Propuesta de valor

### Para clientes

- Descubrir negocios cercanos mediante ubicación GPS.
- Buscar por nombre, categoría, producto o servicio.
- Comparar opciones tomando en cuenta distancia y tipo de negocio.
- Consultar inventario disponible antes de visitar el establecimiento.
- Apartar productos en efectivo con tiempo límite.
- Comprar en línea para recoger en tienda.
- Agendar servicios sin tener que coordinar horarios por mensajes.
- Consultar citas, compras, apartados, PIN y saldo desde un portal personal.

### Para comerciantes

- Tener presencia digital sin construir una tienda propia.
- Publicar productos y servicios desde un panel sencillo.
- Administrar inventario y apartados en tiempo real.
- Organizar citas sin traslapes.
- Solicitar anticipos para reducir cancelaciones y ausencias.
- Validar la llegada del cliente mediante un PIN de cuatro dígitos.
- Dar seguimiento a ventas, citas y apartados desde un solo lugar.
- Obtener mayor visibilidad mediante el Plan Pro.

---

## 4. Alcance y reglas del modelo

Mova está diseñada bajo reglas claras:

1. **No se permite comida preparada.** El proyecto evita competir directamente con aplicaciones de reparto de alimentos.
2. **No existe logística de última milla.** Mova no gestiona repartidores ni envíos a domicilio.
3. **Los productos se recogen en tienda.** La compra digital funciona bajo el modelo Click & Collect.
4. **Los servicios requieren agenda.** Las citas pueden incluir un anticipo para proteger el tiempo del prestador.
5. **El inventario y el dinero se protegen mediante transacciones.** Las operaciones sensibles se procesan de forma atómica en la base de datos.

---

## 5. Modelo de negocio planteado

Mova utiliza un esquema de suscripción Freemium para los comercios.

### Plan Básico

- Sin costo mensual.
- Hasta 10 productos activos.
- Apartados para pagar en efectivo en el establecimiento.
- Hasta 5 citas con anticipo por mes.
- Presencia en resultados de búsqueda por cercanía.

### Plan Pro

- Suscripción mensual, con precio todavía por definir.
- Productos activos ilimitados.
- Pagos en línea para recoger en tienda.
- Citas ilimitadas con anticipo.
- Prioridad en resultados de búsqueda.
- Insignia de negocio verificado.

El modelo permite reducir la barrera de entrada para el pequeño comercio y monetizar cuando el negocio requiere mayor capacidad y visibilidad.

---

## 6. Cómo funciona la experiencia

### Flujo de descubrimiento

1. El cliente entra a la página principal.
2. Mova solicita su ubicación o permite usar una ubicación demo en Roma Norte.
3. El buscador consulta negocios por nombre, categoría, productos y servicios.
4. Los resultados priorizan negocios Pro y después se ordenan por distancia.
5. El cliente abre el perfil público del negocio.

### Flujo de apartado en efectivo

1. El cliente selecciona un producto y la cantidad.
2. El sistema reserva el inventario disponible.
3. El apartado obtiene una vigencia de tres horas.
4. Si no se paga dentro del periodo, el apartado expira y el inventario se libera.
5. El comerciante puede confirmar el pago desde su panel.

### Flujo de compra en línea

1. El cliente selecciona un producto de un negocio Pro.
2. El pago se procesa actualmente mediante un simulador.
3. El inventario total y disponible se actualiza en una transacción.
4. Se genera un folio único de recolección.
5. El cliente recibe un ticket visual con negocio, dirección, producto, cantidad y total pagado.

### Flujo de cita

1. El cliente selecciona un servicio y una fecha.
2. Mova calcula horarios disponibles sin traslapes.
3. El cliente cubre el anticipo mediante el simulador o su monedero.
4. La cita queda agendada y se genera un PIN de cuatro dígitos.
5. El negocio confirma la cita.
6. Al llegar, el cliente comparte el PIN con el comerciante.
7. El comerciante inicia y completa la cita desde su panel.
8. El anticipo se libera al negocio al concluir el servicio.

### Política de cancelación

- Si el cliente cancela antes de la ventana definida por el negocio, recibe el anticipo de vuelta en su monedero.
- Si cancela tarde o no se presenta, el anticipo se transfiere al negocio como compensación.
- Antes de cancelar, la interfaz explica claramente si existirá reembolso o pérdida del anticipo.

---

## 7. Avances completados por etapa

### Etapa 1 — Cimientos, base de datos y acceso

**Estado: completada.**

- Esquema relacional de siete tablas en MySQL/MariaDB.
- Motor InnoDB y relaciones mediante llaves foráneas.
- Conexión segura mediante PDO y sentencias preparadas reales.
- Registro, inicio y cierre de sesión.
- Roles de cliente, negocio y administrador.
- Contraseñas almacenadas mediante hash seguro.
- Respuestas de API en formato JSON.

### Etapa 2 — Buscador por cercanía y vitrina

**Estado: completada.**

- Página principal responsiva.
- Geolocalización mediante API nativa del navegador.
- Ubicación demo en Roma Norte, CDMX.
- Distancia calculada con la fórmula de Haversine en MySQL.
- Búsqueda por negocio, categoría, producto y servicio.
- Filtros mediante categorías.
- Perfiles públicos con pestañas de productos y servicios.
- Priorización de negocios Pro y ordenamiento por distancia.

### Etapa 3 — Agenda protegida y panel del negocio

**Estado: completada.**

- Cálculo de horarios disponibles sin traslapes.
- Duración configurable por servicio.
- Anticipos por cita.
- PIN de verificación de cuatro dígitos.
- Estados de cita desde agendada hasta completada o cancelada.
- Política automática de reembolso y compensación.
- Límite mensual de citas para el Plan Básico.
- Procesamiento de ausencias o `no_show`.
- Dashboard con productos, servicios, citas y apartados.
- Alta inicial para comerciantes sin perfil configurado.

### Etapa 4 — Portal del cliente, imágenes y pulido visual

**Estado: completada.**

- Portal “Mis Citas y Apartados”.
- Consulta de saldo del monedero.
- Recarga rápida mediante pagos simulados.
- Historial de citas con PIN, estado y cancelación.
- Historial de apartados y compras.
- Cuenta regresiva para apartados pendientes.
- Ticket visual Click & Collect.
- Carga física de imágenes JPEG, PNG y WebP.
- Validación de tamaño máximo de 3 MB y MIME real.
- Protección contra ejecución de PHP en la carpeta de cargas.
- Vista previa de imágenes antes de guardar.
- Diseño responsivo basado en variables CSS globales.

### Etapa 5 — Estabilización del dashboard

**Estado: completada.**

- Corrección de la navegación del panel.
- Acceso funcional a Dashboard, Productos, Servicios, Citas y Apartados.
- Navegación móvil completa.
- Creación inicial del perfil de negocio.
- Validación de categorías permitidas.
- Corrección de búsquedas con parámetros PDO únicos.
- Verificación de endpoints para los planes Básico y Pro.

### Trabajo adicional completado

- Cambio integral de marca a **Mova**.
- Migración de la base activa al nombre `mova` sin pérdida de registros.
- Ampliación de los datos demo con ocho tiendas adicionales.
- Incorporación de categorías como barbería, bazar, boutique, taller, servicio técnico, ropa, joyería y comercio local.
- Script idempotente para volver a cargar tiendas demo sin duplicarlas.
- Análisis técnico de mejoras futuras.

---

## 8. Estado actual de la demostración

La base local activa contiene actualmente:

| Elemento | Cantidad |
|---|---:|
| Usuarios | 14 |
| Negocios activos | 11 |
| Productos activos | 21 |
| Servicios activos | 13 |
| Citas registradas | 3 |
| Apartados registrados | 3 |
| Movimientos contables | 8 |

La demostración incluye negocios Pro y Básicos ubicados en distintas zonas de CDMX. Con la ubicación demo, la portada permite observar el orden por plan y distancia.

Todas las cuentas de prueba utilizan la contraseña `Demo1234!`. Algunos accesos disponibles son:

- `estetica@demo.mx`
- `perfumeria@demo.mx`
- `barberia@demo.mx`
- `taller@demo.mx`
- `tecnico@demo.mx`
- `joyeria@demo.mx`
- `ana@cliente.mx`

---

## 9. Arquitectura técnica actual

Mova fue construida sin frameworks ni librerías externas.

### Frontend

- HTML5 semántico.
- CSS3 modular y Mobile-First.
- Variables globales para color, tipografía, espacios, bordes y estados.
- JavaScript Vanilla ES6+.
- `fetch` para comunicación con la API.
- API nativa de geolocalización.
- `FileReader` para vista previa de imágenes.

### Backend

- PHP 8.2 nativo.
- API organizada por módulos.
- PDO con sentencias preparadas reales.
- Sesiones PHP para autenticación.
- Validación de permisos por rol y propiedad del negocio.
- Respuestas JSON reutilizables.
- Transacciones para operaciones de inventario y dinero.

### Datos

- MySQL/MariaDB.
- Siete tablas principales: usuarios, negocios, productos, apartados, servicios, citas y movimientos contables.
- Índices por rol, estado, negocio, ubicación e inventario.
- Llaves foráneas y motor InnoDB.
- Libro contable para auditar movimientos de dinero.

### Ventajas de la arquitectura

- Bajo consumo de recursos.
- Instalación sencilla en XAMPP o hosting compartido.
- Sin dependencia de paquetes de terceros.
- Código y diseño fáciles de modificar.
- Separación clara entre interfaz, lógica de cliente, API y base de datos.

---

## 10. Diferenciadores del prototipo

1. **Enfoque específico:** comercio local no alimentario y servicios cercanos.
2. **Descubrimiento por distancia:** no depende únicamente de popularidad o publicidad.
3. **Compra sin logística propia:** Click & Collect reduce complejidad operativa.
4. **Protección para prestadores:** anticipos, PIN y política de cancelación.
5. **Entrada Freemium:** permite adoptar la plataforma antes de pagar.
6. **Inventario protegido:** los apartados bloquean unidades y las liberan al expirar.
7. **Auditoría financiera:** los movimientos de dinero quedan registrados.
8. **Tecnología ligera:** no requiere infraestructura compleja para funcionar.

---

## 11. Qué se puede demostrar hoy en un pitch

### Demostración recomendada de 5 a 7 minutos

1. Abrir la página principal de Mova.
2. Activar “Usar ubicación demo”.
3. Mostrar que los negocios se ordenan por plan y distancia.
4. Buscar “perfume”, “manicure” o “reparación”.
5. Entrar a un perfil y alternar entre productos y servicios.
6. Iniciar sesión como cliente.
7. Apartar un producto o completar una compra simulada.
8. Mostrar el ticket de recolección.
9. Agendar un servicio y enseñar el PIN de la cita.
10. Entrar como comerciante y mostrar el dashboard.
11. Enseñar inventario, servicios, citas y apartados.
12. Subir o previsualizar una imagen de producto.

Esta secuencia muestra el valor para los dos lados de la plataforma sin depender de integraciones externas.

---

## 12. Estado real y limitaciones actuales

El prototipo es funcional para demostración y validación de producto, pero todavía no debe presentarse como una plataforma lista para operación comercial en producción.

### Funciones simuladas o pendientes de integración

- Los pagos son simulados; todavía no existe conexión con Mercado Pago, Stripe u otro proveedor.
- No hay proceso real de suscripción o facturación del Plan Pro.
- Los correos y notificaciones aún no se envían mediante un proveedor externo.
- No existe recuperación de contraseña por correo.
- Los vencimientos se procesan al consultar datos; falta una tarea programada independiente.
- Los horarios de atención todavía no son configurables por cada negocio.

### Refuerzos necesarios antes de producción

- Protección CSRF.
- Configuración de credenciales mediante variables de entorno.
- Manejo global y registro de errores.
- Pruebas automatizadas de inventario, dinero, permisos y concurrencia.
- Webhooks de pago firmados e idempotentes.
- Copias de seguridad y migraciones versionadas.
- Accesibilidad completa en modales y navegación por teclado.
- Políticas de privacidad, términos de uso y tratamiento de datos.

---

## 13. Próxima etapa recomendada

La siguiente etapa debería enfocarse en preparar una prueba piloto controlada.

### Objetivos propuestos

1. Fortalecer seguridad y manejo de errores.
2. Implementar horarios configurables por negocio.
3. Añadir logotipo y portada para los perfiles.
4. Incorporar notificaciones de citas y apartados.
5. Crear filtros y reportes operativos para comerciantes.
6. Agregar pruebas automatizadas de los flujos críticos.
7. Preparar la integración con un proveedor de pagos real.
8. Medir comportamiento con un pequeño grupo de negocios piloto.

### Indicadores sugeridos para el piloto

- Negocios registrados y negocios activos.
- Productos y servicios publicados.
- Búsquedas que terminan en visita a un perfil.
- Tasa de apartados completados.
- Tasa de conversión de compra en línea.
- Citas reservadas y completadas.
- Cancelaciones a tiempo, cancelaciones tardías y ausencias.
- Tiempo promedio para confirmar un apartado o una cita.
- Conversión de Plan Básico a Plan Pro.
- Retención semanal de clientes y comerciantes.

Estos indicadores todavía no representan resultados obtenidos; son las métricas que deben instrumentarse durante la siguiente etapa.

---

## 14. Mensaje central para el pitch

> Mova ayuda a los comercios y prestadores de servicios locales a ser encontrados por las personas que ya están cerca, organizar ventas y citas, y reducir pérdidas por inventario bloqueado o cancelaciones, sin depender de redes sociales ni asumir la complejidad de una plataforma de reparto.

---

## 15. Conclusión

Mova ha pasado de ser una idea a un prototipo funcional con los principales recorridos de cliente y comerciante conectados a una base de datos real. El proyecto ya permite demostrar descubrimiento por cercanía, catálogo, apartados, compra para recoger, agenda con anticipo, política de cancelación, monedero, ticket de recolección y administración del negocio.

El siguiente reto no es agregar funciones de manera indiscriminada, sino fortalecer seguridad, medición y operación para validar Mova con comercios reales y convertir el prototipo en un piloto confiable.
