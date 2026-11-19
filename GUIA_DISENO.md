# 🎨 Guía de Personalización de Diseño (Design System)

Esta plataforma está construida con **CSS3 puro y Variables Nativas (Design Tokens)**. Cualquier cambio visual se realiza de forma centralizada sin necesidad de compilar nada (sin SASS, sin Webpack, sin npm).

---

## 1. Archivo Maestro: `css/variables.css`

Para cambiar la apariencia completa del proyecto, **solo necesitas editar este archivo**. Al modificar una variable, el cambio se refleja automáticamente en todas las pantallas (`index.html`, `negocio.html`, `panel-negocio.html` y `login.html`).

### A. Paleta de Colores de la Marca (Líneas 18-28)
```css
--color-primary:        #6C63FF;   /* Color dominante: botones principales, badges activos, bordes */
--color-primary-dark:   #574FCC;   /* Hover al pasar el mouse por botones principales */
--color-primary-light:  #EAE9FF;   /* Fondos suaves, píldoras y badges */

--color-secondary:      #FF6584;   /* Color de acento: precios, ofertas, avisos */
--color-secondary-dark: #E04E6A;   /* Hover de elementos secundarios */

/* Gradiente del Banner Principal (Hero de index.html) */
--hero-gradient-start:  #6C63FF;
--hero-gradient-end:    #9C6FFF;
```

### B. Formas y Bordes (Líneas 30-33)
Si deseas un diseño más redondeado o más sobrio/cuadrado:
```css
--card-radius:   1rem;    /* 16px para tarjetas de productos y negocios */
--btn-radius:    0.5rem;  /* 8px para botones */
--input-radius:  0.5rem;  /* 8px para campos de texto */
```

### C. Tipografía (Líneas 70-74)
Puedes agregar cualquier fuente de Google Fonts en el `<head>` de los HTML y cambiarla aquí:
```css
--font-sans: 'Segoe UI', system-ui, -apple-system, sans-serif;
```

---

## 2. Presets de Temas Listos para Usar

En la parte inferior de `css/variables.css` encontrarás bloques comentados listos para activar. Solo descomenta el que desees:

1. **Tema Esmeralda & Oro:** Ideal para spas, barberías premium y boutiques de lujo.
2. **Tema Coral Sunset:** Cálido, enérgico y moderno para bazares y moda.
3. **Tema Blue Tech:** Corporativo, limpio y de alta confiabilidad.

---

## 3. Estructura de Archivos CSS

| Archivo | ¿Para qué sirve? | ¿Cuándo editarlo? |
|---|---|---|
| `css/variables.css` | **Design Tokens**: Colores, fuentes, sombras y espaciados. | **Siempre** que quieras cambiar colores, fuentes o bordes. |
| `css/global.css` | Reset CSS, tipografía base, utilidades de flex/grid y botones. | Si necesitas cambiar estilos globales de etiquetas HTML. |
| `css/componentes.css` | Tarjetas de negocio, modales, calendario de citas, chips y badges. | Si deseas alterar la estructura visual interna de una tarjeta o modal. |

---

## 4. Reglas Importantes para No Afectar la Lógica

Para no romper el funcionamiento de JavaScript:
* **No elimines los IDs** (por ejemplo: `id="search-input"`, `id="negocios-grid"`, `id="login-form"`).
* **No renombres las clases de estado** (por ejemplo: `.active`, `.selected`, `.is-error`, `.badge--pro`).
* **Usa siempre variables:** En lugar de poner colores fijos como `color: #6C63FF;`, usa `color: var(--color-primary);`. Así tus cambios se propagarán perfectamente.

---

## 5. Tokens semánticos y personalización puntual

Además de la marca principal, `css/variables.css` concentra los contrastes y estados. El diseñador puede cambiar el sistema completo sin buscar colores dentro de los componentes:

```css
--color-on-action:       #FFFFFF; /* Texto sobre botones o fondos de marca */
--color-success-text:    #15803D;
--color-warning-text:    #92400E;
--color-danger-text:     #991B1B;
--color-info-text:       #1D4ED8;
--color-overlay:         rgba(10,10,20,0.60);
--focus-ring-primary:    rgba(108,99,255,0.15);
```

Los badges también tienen tokens separados de fondo y texto (`--badge-*-bg` y `--badge-*-text`). Esto permite ajustar contraste, modo oscuro o identidad visual sin modificar `componentes.css`.

Para cambios específicos de una pantalla o componente:

| Elemento | Selector principal en `css/componentes.css` |
|---|---|
| Portal del cliente | `.cliente-*` |
| Monedero | `.monedero-cliente` |
| PIN de cita | `.cita-pin` |
| Ticket de recolección | `.ticket-recoleccion*` |
| Vista previa de fotografía | `.upload-preview` |
| Ubicación de demostración | `.geo-demo-row` |
| Alta inicial del negocio | `.panel-onboarding*` |

El modo oscuro automático permanece disponible mediante `prefers-color-scheme`. El diseñador puede conservarlo, modificar sus tokens o retirar ese bloque según el criterio visual final.
