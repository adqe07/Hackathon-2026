# Plan de Desarrollo: Mova — Plataforma Web de Comercio y Servicios Cercanos

## 1. Visión General del Proyecto
Plataforma web responsiva (*Mobile-First*) diseñada para que pequeños comerciantes y prestadores de servicios locales (estéticas, barberías, perfumerías, bazares, talleres, etc. — **excluyendo estrictamente comida preparada**) tengan su propia vitrina digital y agenda profesional sin depender del algoritmo de redes sociales.

### Propuesta de Valor
* **Para el Negocio:** Gestión de catálogo con inventario en tiempo real, agenda de citas protegida contra cancelaciones mediante anticipos digitales y visibilidad por cercanía geográfica.
* **Para el Cliente:** Buscador por cercanía que prioriza negocios, productos y servicios más próximos a su ubicación GPS, con opciones de compra en línea (recoger en tienda) o apartado para pago en efectivo.

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
* Cada servicio tiene: nombre, descripción, duración, precio fijo y una garantía opcional definida como monto fijo, porcentaje o sin garantía.
* El comerciante decide si permite reservar pagando sólo la garantía, pagar el total o ambas alternativas.
* **Máquina de Estados de la Cita:**
  * `AGENDADA` (Garantía o pago retenido en custodia `ESCROW`).
  * `CONFIRMADA` (Aceptada por el negocio).
  * `EN_CURSO` (Confirmación mutua: cada parte genera un código temporal de seis dígitos y valida el de la contraparte).
  * `RETENCION_24H` (Servicio finalizado y pago retenido durante 24 horas para permitir disputas).
  * `COMPLETADA` (Fondos liberados al negocio después de la retención si no existe disputa).
  * `CANCELADA_A_TIEMPO` (Antes de la hora exacta programada → reembolso completo).
  * `NO_SHOW` (Después de 15 minutos de tolerancia, más una prórroga aceptada si existe). El negocio puede perdonar o retener 25%, 50%, 75% o 100% de la garantía; nunca más que la garantía.
* Cualquiera de las partes puede solicitar una prórroga de 10, 15 o 30 minutos y la otra debe aceptarla.
* Las disputas retienen los fondos, admiten evidencias durante 48 horas y tienen una meta de resolución de siete días hábiles.

### C. Buscador por Cercanía
* Búsqueda unificada por texto (`LIKE` / `FULLTEXT`) sobre nombre de negocio, categoría, título de producto o servicio.
* Ordenamiento prioritario: `plan_suscripcion DESC` (Pro antes que Básico) y `distancia_km ASC` (más cercanos al usuario primero).

### D. Modelo de Monetización (Suscripción Freemium)
* **Plan Básico ($0/mes):** Hasta 10 productos activos, solo apartado en efectivo, hasta 5 citas con anticipo al mes.
* **Plan Pro (Suscripción mensual):** Productos ilimitados, pago en línea habilitado, citas ilimitadas con anticipo, prioridad en resultados de búsqueda e insignia de verificado.

---

## 4. Arquitectura de Carpetas

```text
/proyecto-mova
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
│   ├── mapa.js                 # Mapa SVG propio con proyección de coordenadas y distancias
│   ├── buscador.js             # Lógica de filtros y renderizado dinámico en index.html
│   ├── agenda.js               # Calendario, horarios, garantías y ticket de recolección
│   ├── cliente.js              # Portal personal, tarjetas simuladas y seguimiento de citas
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
│   ├── schema.sql              # Esquema base original
│   └── etapa6_confianza_pagos.sql # Extensión aditiva de garantías, pagos y disputas
└── uploads/
    └── productos/              # Imágenes subidas por los negocios
```

---

## 5. Estado de Implementación

- **Etapa 1 — Cimientos, Base de Datos y Acceso:** Completada.
- **Etapa 2 — Buscador por Cercanía y Vitrina:** Completada.
- **Etapa 3 — Agenda Blindada y Panel del Negocio:** Completada, incluyendo límite mensual del Plan Básico y procesamiento automático de `no_show`.
- **Etapa 4 — Portal del Cliente, Fotos y Pulido Visual:** Completada el 19 de noviembre de 2026.
- **Etapa 5 — Estabilización del Dashboard del Comerciante:** Completada el 19 de noviembre de 2026.
- **Etapa 6 — Confianza, Garantías y Pagos Simulados:** Versión piloto funcional implementada el 19 de noviembre de 2026.

El cierre de la Etapa 4 incluye portal del cliente, carga física segura de imágenes, tickets Click & Collect, ubicación de demostración, transacciones para inventario y monederos, y centralización de colores y estados en `css/variables.css`. El folio de recolección se deriva de forma estable del ID del apartado sin modificar el esquema existente.

La Etapa 5 habilita el alta inicial del perfil de negocio y verifica el funcionamiento integral de Dashboard, Productos, Servicios, Citas y Apartados tanto para Plan Básico como Pro.

La Etapa 6 incorpora tarjetas simuladas tokenizadas (sin guardar número completo ni CVV), garantías configurables, pago de garantía o total, códigos temporales bilaterales, tolerancia y prórrogas, resolución flexible de ausencias, confirmación final, retención de 24 horas y disputas con resolución administrativa. El Plan Básico aplica una comisión piloto del 1% en servicios; el Plan Pro permanece con 0% durante la fase inicial.

La experiencia de descubrimiento incluye un motor cartográfico propio de Mova en JavaScript Vanilla, con proyección Web Mercator y mosaicos de OpenStreetMap debidamente atribuidos. La portada representa los resultados visibles, permite zoom y desplazamiento y resalta el negocio más cercano; cada perfil compara la ubicación del cliente con las coordenadas registradas del establecimiento.

## 6. Datos de demostración ampliados

Para evaluar la portada, la búsqueda por cercanía y los perfiles con una oferta representativa, el archivo `sql/demo_tiendas.sql` agrega de forma idempotente ocho negocios adicionales de barbería, bazar, boutique, taller, servicio técnico, ropa, joyería y comercio local. Incluye 16 productos y 9 servicios, usa coordenadas de prueba en CDMX y excluye comida preparada.

El script puede ejecutarse después de `sql/schema.sql`. Todas las cuentas de negocio demo utilizan la contraseña `Demo1234!`.
