# Guía rápida y detallada del backend de Mova

Esta guía explica qué hace el backend, qué archivo interviene en cada función y cómo se protege la información. Está organizada para entender primero la idea general y después profundizar únicamente en el módulo que interese.

## 1. El backend en una frase

El backend es la parte de Mova que recibe solicitudes del navegador, verifica permisos y reglas de negocio, consulta o modifica MySQL y devuelve una respuesta en formato JSON.

Por ejemplo, cuando un cliente pulsa **Apartar producto**, JavaScript envía una petición a PHP. PHP comprueba la sesión, valida el producto y el inventario, bloquea temporalmente la fila en MySQL, descuenta el stock disponible, crea el apartado y responde con el resultado.

## 2. Mapa de archivos

| Archivo | Responsabilidad principal |
|---|---|
| `api/config/db.php` | Conexión PDO, respuestas JSON, lectura de datos y autenticación común |
| `api/auth.php` | Registro, inicio de sesión, cierre de sesión y usuario actual |
| `api/buscar.php` | Búsqueda de negocios, filtros, distancia y paginación |
| `api/negocios.php` | Perfil público, creación y edición del negocio, estadísticas del panel |
| `api/productos.php` | Productos, imágenes, inventario, apartados, tickets y servicios |
| `api/citas.php` | Horarios, citas, PIN, anticipos, cancelaciones y ausencias |
| `api/pagos_simulador.php` | Simulación de pagos y recargas de monedero |
| `sql/schema.sql` | Estructura completa de las siete tablas y datos iniciales |
| `sql/demo_tiendas.sql` | Tiendas, productos y servicios adicionales para demostración |

## 3. Cómo viaja una petición

El recorrido normal es el siguiente:

1. Una página ejecuta JavaScript.
2. `js/api.js` construye una URL como `api/citas.php?action=crear`.
3. El navegador envía una petición `GET` o `POST`.
4. El archivo PHP lee el parámetro `action`.
5. PHP valida el método HTTP, los datos y la sesión.
6. PHP obtiene la conexión PDO desde `api/config/db.php`.
7. Se ejecutan consultas preparadas contra MySQL.
8. Si se modifican dinero o inventario, se utiliza una transacción.
9. PHP devuelve JSON y un código HTTP.
10. JavaScript muestra el resultado al usuario.

Ejemplo conceptual:

```text
Botón Agendar
    → js/agenda.js
    → POST api/citas.php?action=crear
    → valida cliente, servicio, horario y límite del plan
    → crea cita y registra anticipo
    → devuelve { success: true, cita: {...} }
```

## 4. Archivo base: `api/config/db.php`

Este es el archivo más importante porque todos los endpoints lo incluyen.

### `getPDO()`

Crea y reutiliza una conexión con la base `mova`.

La conexión utiliza:

- PDO nativo de PHP.
- Codificación `utf8mb4`.
- Excepciones cuando MySQL informa un error.
- Resultados como arreglos asociativos.
- Sentencias preparadas reales mediante `ATTR_EMULATE_PREPARES = false`.

Las sentencias preparadas separan el código SQL de los valores enviados por el usuario. Esto reduce el riesgo de inyección SQL.

Ejemplo simplificado:

```php
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
$stmt->execute([$email]);
```

El correo no se concatena dentro del SQL; se envía como un valor independiente.

### `jsonResponse()`

Termina la petición y devuelve JSON con el código HTTP indicado.

Ejemplo:

```php
jsonResponse([
    'success' => false,
    'error' => 'No tienes permiso.'
], 403);
```

Los códigos más usados son:

| Código | Significado |
|---:|---|
| 200 | Consulta correcta |
| 201 | Registro creado |
| 400 | Acción o petición incorrecta |
| 401 | No existe una sesión válida |
| 403 | Existe sesión, pero el rol no tiene permiso |
| 404 | El registro no existe |
| 409 | Conflicto, por ejemplo correo o negocio duplicado |
| 422 | Datos recibidos inválidos |
| 500 | Error interno |
| 503 | MySQL no está disponible |

### `getJsonInput()`

Lee el cuerpo JSON de una petición `POST`, lo convierte a un arreglo PHP y rechaza JSON inválido.

### `requireAuth()`

Comprueba que exista una sesión y, opcionalmente, que el usuario tenga un rol específico.

```php
$cliente = requireAuth('cliente');
$comerciante = requireAuth('negocio');
$cualquierUsuario = requireAuth();
```

Esto evita repetir la misma lógica de permisos en todos los endpoints.

## 5. Autenticación: `api/auth.php`

Este archivo administra la identidad del usuario.

La acción se selecciona desde la URL:

| Acción | Método | Función |
|---|---|---|
| `register` | POST | Crear una cuenta |
| `login` | POST | Iniciar sesión |
| `logout` | POST | Cerrar y destruir la sesión |
| `me` | GET | Consultar al usuario autenticado |

### Registro

`handleRegister()` valida:

- Nombre entre 2 y 120 caracteres.
- Correo con formato válido y no repetido.
- Contraseña de al menos 8 caracteres.
- Al menos una mayúscula y un número.
- Rol público limitado a `cliente` o `negocio`.

La contraseña nunca se guarda directamente. PHP genera un hash Bcrypt con `password_hash()`.

### Inicio de sesión

`handleLogin()` busca el correo y valida la contraseña con `password_verify()`.

Si los datos son correctos:

- Guarda los datos mínimos del usuario en `$_SESSION`.
- Regenera el identificador de sesión para evitar fijación de sesión.
- Actualiza automáticamente hashes antiguos cuando es necesario.

Las cookies de sesión están configuradas con:

- `HttpOnly`, para impedir acceso desde JavaScript.
- `SameSite=Lax`, para reducir envíos desde otros sitios.
- Modo estricto de sesión.

### Usuario actual

`handleMe()` no confía únicamente en la información almacenada en sesión. Vuelve a consultar la base para obtener nombre, rol y saldo actualizados. Si el rol es negocio, también devuelve su perfil comercial.

## 6. Búsqueda: `api/buscar.php`

Es un endpoint público de solo lectura.

Ejemplo:

```text
GET api/buscar.php?action=buscar&q=perfume&categoria=perfumeria&lat=19.4194&lon=-99.1628
```

Acepta:

- `q`: texto libre.
- `categoria`: filtro exacto.
- `lat` y `lon`: ubicación del cliente.
- `pagina`: página solicitada.
- `limite`: resultados por página, con máximo de 50.

Busca coincidencias en:

- Nombre, descripción y categoría del negocio.
- Título y descripción de productos.
- Nombre y descripción de servicios.

### Distancia

Si recibe coordenadas, MySQL aplica la fórmula de Haversine y calcula kilómetros entre el cliente y cada negocio.

El orden es:

1. Negocios con Plan Pro.
2. Menor distancia.
3. ID más reciente como desempate.

Los parámetros de texto, ubicación, límite y página se vinculan mediante PDO; no se concatenan valores del usuario en la consulta.

## 7. Negocios: `api/negocios.php`

Este archivo administra el perfil comercial.

| Acción | Acceso | Función |
|---|---|---|
| `perfil` | Público | Datos completos de un negocio |
| `dashboard` | Dueño | Indicadores del panel |
| `crear` | Rol negocio | Crear el perfil inicial |
| `actualizar` | Dueño | Editar datos del negocio |

### Crear negocio

`handleCrear()` valida:

- Nombre y categoría.
- Categorías permitidas, excluyendo comida preparada.
- Longitudes máximas.
- Latitud entre -90 y 90.
- Longitud entre -180 y 180.
- Ventana de cancelación entre 1 y 168 horas.

Después abre una transacción, bloquea al usuario con `FOR UPDATE`, verifica que no tenga otro negocio y crea el perfil con Plan Básico.

### Perfil público

`handlePerfil()` reúne los datos necesarios para `negocio.html`: información comercial, dirección, plan, verificación y distancia opcional.

### Dashboard

`handleDashboard()` exige rol negocio y comprueba que el negocio pertenezca al usuario de sesión. Calcula indicadores como productos activos, citas, apartados y movimientos.

La verificación de propiedad es fundamental: no basta con conocer el ID de otro negocio para administrarlo.

## 8. Productos, apartados y servicios: `api/productos.php`

Es el archivo con más funciones porque agrupa tres módulos relacionados.

### Acciones de productos

| Acción | Función |
|---|---|
| `listar` | Consultar productos activos |
| `crear` | Crear producto |
| `editar` | Modificar producto |
| `eliminar` | Desactivarlo sin borrar su historial |

El Plan Básico admite hasta 10 productos activos. El límite se comprueba dentro de una transacción y con el negocio bloqueado para evitar altas simultáneas que lo excedan.

### Carga de imágenes

Crear y editar aceptan JSON o `multipart/form-data`.

Cuando llega un archivo:

1. Comprueba que no supere 3 MB.
2. Verifica su MIME real mediante `finfo`.
3. Solo acepta JPEG, PNG o WebP.
4. Genera un nombre único con `uniqid()`.
5. Guarda el archivo en `uploads/productos/`.
6. La carpeta de cargas bloquea la ejecución de PHP mediante `.htaccess`.

### Apartados

| Acción | Acceso | Función |
|---|---|---|
| `apartar` | Cliente | Crear una reserva de producto |
| `apartados` | Dueño | Consultar apartados del negocio |
| `mis_apartados` | Cliente | Consultar compras y apartados propios |
| `ticket` | Cliente propietario | Obtener ticket de recolección |
| `marcar_pagado` | Dueño | Confirmar pago en tienda |

Al apartar:

1. Verifica que el negocio esté activo.
2. Solo permite pago en línea para negocios Pro.
3. Bloquea el producto con `FOR UPDATE`.
4. Comprueba que exista stock suficiente.
5. Descuenta `stock_disponible`.
6. Crea el apartado pendiente.
7. Asigna tres horas de vigencia al pago en efectivo.
8. Confirma toda la operación con `commit()`.

Si cualquier paso falla, `rollBack()` revierte todo. Así no puede crearse un apartado sin descontar inventario ni descontarse inventario sin crear el apartado.

### Expiración

Antes de cada operación del archivo, `expirarApartadosVencidos()` busca apartados pendientes vencidos. Los bloquea, cambia su estado a `expirado` y devuelve las unidades a `stock_disponible`.

### Servicios

Las acciones `servicios`, `crear_servicio`, `editar_servicio` y `eliminar_servicio` administran nombre, descripción, duración, precio estimado y anticipo.

## 9. Agenda y citas: `api/citas.php`

Este archivo contiene la lógica más delicada después de inventario y pagos.

| Acción | Acceso | Función |
|---|---|---|
| `slots` | Público | Calcular horarios disponibles |
| `crear` | Cliente | Reservar una cita |
| `listar` | Cliente o negocio | Consultar citas autorizadas |
| `confirmar` | Dueño | Confirmar cita agendada |
| `iniciar` | Dueño + PIN | Marcar cita en curso |
| `completar` | Dueño | Finalizar y liberar anticipo |
| `cancelar` | Cliente o dueño autorizado | Aplicar cancelación y dinero |

### Cálculo de horarios

`handleSlots()`:

- Lee la duración del servicio.
- Usa actualmente un horario estándar de 09:00 a 19:00.
- Genera opciones cada 30 minutos.
- Descarta citas que se traslapen.
- Para el día actual, exige un margen mínimo de 15 minutos.

### Crear cita

`handleCrearCita()` abre una transacción y:

1. Valida cliente, negocio, servicio y fecha.
2. Obtiene duración y anticipo.
3. Comprueba el límite mensual de 5 citas con anticipo para Plan Básico.
4. Bloquea citas relevantes para evitar reservas simultáneas del mismo horario.
5. Calcula inicio y fin.
6. Genera un PIN aleatorio de cuatro dígitos.
7. Crea la cita como `agendada`.
8. Registra el anticipo en `movimientos_ledger`.

### Máquina de estados

La cita avanza normalmente así:

```text
agendada → confirmada → en_curso → completada
```

También puede terminar como:

- `cancelada_tiempo`
- `cancelada_tarde`
- `no_show`

No cualquier estado puede cambiar a otro. Cada función comprueba el estado actual antes de actualizarlo.

### PIN

Para iniciar la cita, el comerciante debe proporcionar el PIN visible en el portal del cliente. El backend compara el código y solo cambia a `en_curso` si pertenece a esa cita y al negocio autenticado.

### Liberación del anticipo

Al completar una cita:

- El estado cambia a `completada`.
- El saldo del dueño aumenta.
- Se registra la liberación en `movimientos_ledger`.

### Cancelación

El backend calcula las horas restantes y las compara con `ventana_cancelacion_horas` del negocio.

- A tiempo: devuelve el anticipo al monedero del cliente.
- Tarde: acredita el anticipo al dueño.
- Cancelación del dueño: se considera reembolsable.

Todo ocurre en una transacción para que estado, saldo y ledger siempre coincidan.

### Ausencias

Antes de cada operación, `procesarNoShowsVencidos()` busca citas agendadas o confirmadas que comenzaron hace más de 15 minutos. Las marca como `no_show`, acredita el anticipo al negocio y crea el movimiento contable.

## 10. Pagos: `api/pagos_simulador.php`

Este archivo imita lo que en producción realizaría una pasarela como Mercado Pago o Stripe.

| Acción | Función |
|---|---|
| `pagar_apartado` | Confirmar compra en línea |
| `recargar_monedero` | Aumentar saldo del cliente |

### Pago de apartado

`handlePagarApartado()` exige sesión de cliente y verifica que el apartado pertenezca a esa cuenta.

Dentro de una transacción:

1. Bloquea el apartado.
2. Comprueba que esté pendiente y sea de tipo `online`.
3. Cambia su estado a `pagado`.
4. Descuenta definitivamente `stock_total`.
5. Genera una referencia simulada.
6. Acredita el monto al dueño del negocio.
7. Registra el movimiento en el ledger.
8. Devuelve los datos del ticket de recolección.

### Recarga

`handleRecargarMonedero()` aumenta el saldo del cliente y registra el movimiento correspondiente.

Este módulo es funcional para demostración, pero no cobra dinero real. En producción debe reemplazarse por webhooks firmados e idempotentes de un proveedor de pagos.

## 11. Base de datos: `sql/schema.sql`

La base se llama `mova` y contiene siete tablas.

| Tabla | Qué guarda |
|---|---|
| `usuarios` | Cuentas, roles y saldo del monedero |
| `negocios` | Perfil, ubicación, plan y política de cancelación |
| `productos` | Catálogo, precios e inventario |
| `apartados` | Reservas, compras, vencimiento y tipo de pago |
| `servicios` | Duración, precio y anticipo |
| `citas` | Horarios, estados, anticipo y PIN |
| `movimientos_ledger` | Auditoría de entradas, retenciones, pagos y reembolsos |

Las tablas utilizan InnoDB y llaves foráneas. Esto impide, por ejemplo, que exista una cita ligada a un negocio inexistente.

### Dos columnas de stock

- `stock_total`: unidades físicas que siguen perteneciendo a la tienda.
- `stock_disponible`: unidades que todavía pueden reservar otros clientes.

Al crear un apartado se reduce el disponible. Cuando se confirma la compra se reduce el total. Si el apartado expira, se recupera únicamente el disponible.

### Ledger

`movimientos_ledger` funciona como un historial contable. El saldo actual está en `usuarios`, pero el ledger explica por qué cambió: recarga, pago, reembolso, liberación de anticipo o compensación.

## 12. Conceptos técnicos esenciales

### API REST ligera

Los archivos PHP exponen operaciones mediante HTTP y JSON. No existe un framework; el parámetro `action` actúa como enrutador sencillo.

### PDO

Es la interfaz nativa de PHP para MySQL. Prepara consultas y vincula valores de forma segura.

### Sesión

PHP mantiene una cookie `PHPSESSID`. El servidor usa ese identificador para reconocer al usuario sin enviar correo y contraseña en cada petición.

### Transacción

Una transacción agrupa varios cambios como una sola operación:

```text
beginTransaction
    cambios de inventario, estado y dinero
commit
```

Si algo falla:

```text
rollBack
```

### `FOR UPDATE`

Bloquea temporalmente una fila durante la transacción. Evita que dos clientes compren simultáneamente la última unidad o reserven el mismo horario.

### Desactivación lógica

Productos y servicios se marcan como inactivos en vez de borrarse físicamente. Esto conserva relaciones e historial.

## 13. Qué está protegido actualmente

- Inyección SQL mediante consultas preparadas.
- Contraseñas mediante Bcrypt.
- Fijación de sesión mediante regeneración de ID.
- Acciones por rol mediante `requireAuth()`.
- Propiedad del negocio en operaciones administrativas.
- Concurrencia de inventario y citas con transacciones y bloqueos.
- Ejecución de archivos PHP dentro de cargas.
- Tipos y tamaños de imágenes.
- Auditoría básica de movimientos monetarios.

## 14. Qué falta antes de producción

- Token CSRF en acciones que modifican datos.
- Restringir CORS al dominio real.
- Mover credenciales de MySQL a variables de entorno.
- Manejo global y registro centralizado de excepciones.
- Pagos reales con webhooks firmados e idempotencia.
- Tareas cron para expiraciones y ausencias, independientes de las visitas.
- Pruebas automáticas de permisos, concurrencia, dinero e inventario.
- Límites de intentos de inicio de sesión.
- Recuperación y verificación de correo.
- Horarios configurables por negocio.

## 15. Explicación de 60 segundos para un pitch técnico

> El backend de Mova está desarrollado en PHP 8 nativo y expone una API JSON dividida en siete módulos. Utiliza PDO con sentencias preparadas reales para conectarse a MySQL. La autenticación se mantiene con sesiones seguras y cada operación verifica el rol y la propiedad del recurso. Los cambios sensibles, como inventario, pagos, reembolsos y anticipos, se ejecutan dentro de transacciones InnoDB y bloqueos `FOR UPDATE` para evitar inconsistencias cuando dos personas actúan al mismo tiempo. La búsqueda calcula cercanía con Haversine directamente en MySQL, mientras que citas y apartados tienen reglas automáticas de vencimiento, cancelación y auditoría en un ledger. Actualmente los pagos están simulados, por lo que el siguiente paso para producción es integrar un proveedor real, CSRF, tareas programadas y pruebas automatizadas.

## 16. Orden recomendado para estudiarlo en 10 minutos

1. Leer las secciones 1, 2 y 3 para comprender el recorrido general.
2. Revisar `api/config/db.php` para entender los helpers compartidos.
3. Leer `api/auth.php` para comprender sesiones y roles.
4. Elegir un flujo:
   - Compra: `api/productos.php` y `api/pagos_simulador.php`.
   - Cita: `api/citas.php`.
   - Descubrimiento: `api/buscar.php`.
5. Consultar al final `sql/schema.sql` para relacionar los datos.

Con ese orden se entiende primero el sistema completo y después la lógica crítica sin tener que leer cada línea del código.
