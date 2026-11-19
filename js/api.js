/**
 * js/api.js
 * -------------------------------------------------
 * Wrapper limpio de fetch() para comunicarse con
 * todos los endpoints de /api/*.php
 * -------------------------------------------------
 * Uso:
 *   import { api } from './api.js';
 *   const data = await api.get('auth.php', { action: 'me' });
 *   const data = await api.post('auth.php', { action: 'login' }, body);
 */

// Ruta base dinámica hacia la carpeta api/ relativa a la página actual
function buildApiUrl(endpoint) {
  const base = new URL('.', window.location.href);
  return new URL('api/' + endpoint, base);
}

/**
 * Muestra un toast de notificación en pantalla.
 * @param {string} mensaje
 * @param {'success'|'danger'|'warning'|'default'} tipo
 * @param {number} duracionMs
 */
export function toast(mensaje, tipo = 'default', duracionMs = 3500) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  const el = document.createElement('div');
  el.className = `toast${tipo !== 'default' ? ` toast--${tipo}` : ''}`;
  el.textContent = mensaje;
  container.appendChild(el);

  setTimeout(() => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(10px)';
    el.style.transition = 'all 0.3s ease';
    setTimeout(() => el.remove(), 350);
  }, duracionMs);
}

/**
 * Función interna que realiza la llamada fetch y procesa la respuesta JSON.
 * @param {string} endpoint   - Archivo PHP relativo a /api/ (ej: 'auth.php')
 * @param {string} method     - Método HTTP: 'GET' | 'POST'
 * @param {Object} params     - Query params (añadidos a la URL)
 * @param {Object|null} body  - Cuerpo JSON para POST
 * @returns {Promise<Object>} - Objeto JSON de respuesta
 */
async function request(endpoint, method = 'GET', params = {}, body = null) {
  const url = buildApiUrl(endpoint);

  // Añadir query params
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null) url.searchParams.set(k, v);
  });

  const esFormData = body instanceof FormData;
  const options = {
    method,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      ...(esFormData ? {} : { 'Content-Type': 'application/json' }),
    },
    credentials: 'same-origin', // Incluir cookies de sesión PHP
  };

  if (body !== null) {
    options.body = esFormData ? body : JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url.toString(), options);
  } catch (networkError) {
    // Error de red (sin conexión, servidor caído, etc.)
    throw new ApiError(0, 'Sin conexión con el servidor. Verifica que Apache en XAMPP esté iniciado.');
  }

  const rawText = await response.text();
  let data;
  try {
    data = JSON.parse(rawText);
  } catch (parseError) {
    console.error(`[API Error ${response.status}] Respuesta no es JSON válido de: ${url.pathname}`, rawText);

    // 1. Caso: Se abrió con Live Server o file:// y PHP no se está ejecutando
    if (rawText.trim().startsWith('<?php')) {
      throw new ApiError(
        response.status,
        'Estás abriendo la web con un servidor estático (como Live Server). Debes abrirla en http://localhost/hackathon/ con Apache y MySQL activos en XAMPP.'
      );
    }

    // 2. Caso: PHP emitió un error o advertencia en HTML (XAMPP display_errors)
    const matchWarning = rawText.match(/(?:Fatal error|Warning|Parse error|Notice):\s*(.*?)(?:in\s+<b>.*?<\/b>|on\s+line|\n|<br|$)/i);
    if (matchWarning) {
      throw new ApiError(response.status, `Error PHP del servidor: ${matchWarning[1].replace(/<[^>]*>?/gm, '').trim()}`);
    }

    throw new ApiError(response.status, `Respuesta inesperada del servidor (${response.status}). Revisa la consola para más detalles.`);
  }

  if (!response.ok) {
    // El servidor respondió con 4xx o 5xx
    const mensaje = data?.error || data?.errores?.join(' | ') || `Error ${response.status}`;
    throw new ApiError(response.status, mensaje, data);
  }

  return data;
}

/**
 * Error personalizado de API con código de estado HTTP.
 */
export class ApiError extends Error {
  /**
   * @param {number} status    - Código HTTP (0 = error de red)
   * @param {string} message   - Mensaje legible
   * @param {Object} [data]    - Respuesta completa del servidor
   */
  constructor(status, message, data = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

/**
 * Objeto principal de la API — expone métodos por verbo HTTP.
 */
export const api = {
  /**
   * GET /api/{endpoint}?{params}
   * @param {string} endpoint
   * @param {Object} params
   */
  get(endpoint, params = {}) {
    return request(endpoint, 'GET', params);
  },

  /**
   * POST /api/{endpoint}?{params}
   * @param {string} endpoint
   * @param {Object} params  - Query params (ej: { action: 'login' })
   * @param {Object} body    - Body JSON
   */
  post(endpoint, params = {}, body = {}) {
    return request(endpoint, 'POST', params, body);
  },

  /** Envía multipart/form-data (el navegador define el boundary). */
  postForm(endpoint, params = {}, formData) {
    return request(endpoint, 'POST', params, formData);
  },
};

// ─── Helpers de autenticación ─────────────────────────────────────

/**
 * Obtiene el usuario de sesión activo.
 * Lanza ApiError si no hay sesión.
 * @returns {Promise<Object>} { usuario, negocio }
 */
export async function getUsuarioActual() {
  return api.get('auth.php', { action: 'me' });
}

/**
 * Cierra la sesión del usuario actual.
 * @returns {Promise<void>}
 */
export async function cerrarSesion() {
  await api.post('auth.php', { action: 'logout' });
}

// ─── Helpers de UI ────────────────────────────────────────────────

/**
 * Formatea un número como moneda MXN.
 * @param {number|string} monto
 * @returns {string}  "$ 1,250.00"
 */
export function formatMXN(monto) {
  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
  }).format(parseFloat(monto) || 0);
}

/**
 * Formatea una fecha ISO a texto legible en español.
 * @param {string} isoString
 * @param {boolean} conHora
 * @returns {string}
 */
export function formatFecha(isoString, conHora = false) {
  if (!isoString) return '—';
  const opciones = {
    year: 'numeric', month: 'long', day: 'numeric',
    timeZone: 'America/Mexico_City',
  };
  if (conHora) {
    opciones.hour = '2-digit';
    opciones.minute = '2-digit';
  }
  return new Date(isoString).toLocaleDateString('es-MX', opciones);
}

/**
 * Genera el HTML del badge de estado de una cita.
 * @param {string} estado
 * @returns {string} HTML string
 */
export function badgeEstadoCita(estado) {
  const etiquetas = {
    pendiente_pago:   'Pendiente pago',
    agendada:         'Agendada',
    confirmada:       'Confirmada',
    en_curso:         'En curso',
    completada:       'Completada',
    cancelada_tiempo: 'Cancelada (a tiempo)',
    cancelada_tarde:  'Cancelada (tarde)',
    no_show:          'No show',
  };
  const texto = etiquetas[estado] ?? estado;
  return `<span class="badge badge--${estado.replace(/_/g, '-')}">${texto}</span>`;
}

/**
 * Genera el HTML del badge de estado de un apartado.
 * @param {string} estado
 * @returns {string}
 */
export function badgeEstadoApartado(estado) {
  const etiquetas = {
    pendiente: 'Pendiente',
    pagado:    'Pagado',
    expirado:  'Expirado',
    cancelado: 'Cancelado',
  };
  return `<span class="badge badge--${estado}">${etiquetas[estado] ?? estado}</span>`;
}

/**
 * Bloquea/desbloquea un botón mostrando un spinner de carga.
 * @param {HTMLButtonElement} btn
 * @param {boolean} loading
 * @param {string} [textoOriginal]
 */
export function btnLoading(btn, loading, textoOriginal = '') {
  if (loading) {
    btn.disabled = true;
    btn.dataset.originalText = btn.innerHTML;
    btn.innerHTML = `<span class="spinner"></span> Cargando…`;
  } else {
    btn.disabled = false;
    btn.innerHTML = textoOriginal || btn.dataset.originalText || 'Enviar';
  }
}
