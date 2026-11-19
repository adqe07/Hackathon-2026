# Plan de Desarrollo: Plataforma Web Hiperlocal de Comercio y Servicios

## 1. Visión General del Proyecto
Plataforma web responsiva (*Mobile-First*) diseñada para que pequeños comerciantes y prestadores de servicios locales (estéticas, barberías, perfumerías, bazares, talleres, etc. — **excluyendo estrictamente comida preparada**) tengan su propia vitrina digital y agenda profesional sin depender del algoritmo de redes sociales.

### Propuesta de Valor
* **Para el Negocio:** Gestión de catálogo con inventario en tiempo real, agenda de citas protegida contra cancelaciones mediante anticipos digitales y visibilidad por cercanía geográfica.
* **Para el Cliente:** Buscador hiperlocal que prioriza negocios, productos y servicios más cercanos a su ubicación GPS, con opciones de compra en línea (recoger en tienda) o apartado para pago en efectivo.

---

## 2. Stack Tecnológico (Cero Frameworks)
El desarrollo debe realizarse **100% en tecnologías nativas/puras** para maximizar velocidad de desarrollo, ligereza y facilidad de despliegue en hosting compartido:
* **Frontend:** HTML5 semántico, CSS3 puro (Variables CSS, Flexbox, CSS Grid, diseño responsivo móvil/escritorio) y JavaScript moderno (ES6+ Vanilla, `fetch` API, `navigator.geolocation`).
* **Backend:** PHP 8+ nativo estructurado como API REST ligera (respuestas en formato `application/json`), usando **PDO** con sentencias preparadas para seguridad contra inyección SQL.
* **Base de Datos:** MySQL / MariaDB (motor `InnoDB`, cotejamiento `utf8mb4_unicode_ci`). Uso de la **Fórmula de Haversine** en consultas SQL para cálculo de distancias en kilómetros.
* **Pagos:** Preparado para integración vía Webhooks / API REST (Mercado Pago / Stripe) con simulación funcional en entorno local.

---

## 3. Reglas de Negocio Críticas

### A. Catálogo de Productos (No Comida)
* Cada producto tiene: título, descripción, categoría, precio, fotos y `stock_disponible`.
* **Modalidades de compra:**
  1. *Compra en línea (Click & Collect):* Descuenta stock inmediatamente tras confirmar pago y genera ticket de recolección.
  2. *Apartado en efectivo:* Bloquea `1` unidad del stock por un tiempo límite (`TTL = 3 horas`). Si expira sin marcarse como pagado en tienda, el sistema libera el stock automáticamente al consultar disponibilidad.

### B. Módulo de Servicios y Agenda Blindada
* Cada servicio tiene: nombre, descripción, duración en minutos, precio estimado y monto/porcentaje de anticipo requerido.
* **Máquina de Estados de la Cita:**
  * `PENDIENTE_PAGO` (Bloquea horario por 10 min mientras se paga el anticipo).
  * `AGENDADA` (Anticipo retenido en custodia `ESCROW`).
  * `CONFIRMADA` (Aceptada por el negocio).
  * `EN_CURSO` (Validada en el local mediante **PIN de 4 dígitos** del cliente).
  * `COMPLETADA` (Anticipo liberado al saldo del negocio).
  * `CANCELADA_A_TIEMPO` (Antes de $T_{limite}$ configurado por el negocio → 100% reembolsado al monedero interno del cliente).
  * `CANCELADA_TARDE` / `NO_SHOW` (Después de $T_{limite}$ o tras 15 min de tolerancia sin PIN → 100% del anticipo se transfiere al negocio como compensación).

### C. Buscador Hiperlocal
* Búsqueda unificada por texto (`LIKE` / `FULLTEXT`) sobre nombre de negocio, categoría, título de producto o servicio.
* Ordenamiento prioritario: `plan_suscripcion DESC` (Pro antes que Básico) y `distancia_km ASC` (más cercanos al usuario primero).

### D. Modelo de Monetización (Suscripción Freemium)
* **Plan Básico ($0/mes):** Hasta 10 productos activos, solo apartado en efectivo, hasta 5 citas con anticipo al mes.
* **Plan Pro (Suscripción mensual):** Productos ilimitados, pago en línea habilitado, citas ilimitadas con anticipo, prioridad en resultados de búsqueda e insignia de verificado.

---

## 4. Arquitectura de Carpetas

```text
/proyecto-hiperlocal
├── index.html                  # Landing + Buscador principal por cercanía GPS
├── negocio.html                # Perfil público del negocio (Tabs: Productos y Servicios)
├── panel-negocio.html          # Dashboard privado para gestionar catálogo, apartados y agenda
├── login.html                  # Registro e inicio de sesión (Cliente / Negocio)
├── css/
│   ├── variables.css           # Paleta de colores, tipografías y espaciados
│   ├── global.css              # Reset, layout base, botones, formularios y grid responsivo
│   └── componentes.css         # Tarjetas, modales, selector de horarios y badges de estado
├── js/
│   ├── api.js                  # Wrapper limpio de fetch() para comunicarse con /api/*.php
│   ├── geolocalizacion.js      # Captura de GPS del navegador y fallback manual
│   ├── buscador.js             # Lógica de filtros y renderizado dinámico en index.html
│   ├── agenda.js               # Calendario, slots disponibles, cálculo de anticipo y PIN
│   └── panel.js                # CRUD de productos/servicios, upload de fotos y gestión de citas
├── api/
│   ├── config/
│   │   └── db.php              # Conexión PDO y helpers de respuesta JSON
│   ├── auth.php                # Login, registro y validación de sesión PHP
│   ├── buscar.php              # Búsqueda Haversine por distancia, categoría y texto
│   ├── negocios.php            # Perfil público y actualización de datos/ubicación
│   ├── productos.php           # CRUD inventario, apartados con expiración y liberación de stock
│   ├── citas.php               # Creación de cita, validación de traslapes, estados y PIN
│   └── pagos_simulador.php     # Simulador de webhook para acreditar anticipos y compras online
├── sql/
│   └── schema.sql              # Script completo de creación de tablas e índices
└── uploads/
    └── productos/              # Imágenes subidas por los negocios