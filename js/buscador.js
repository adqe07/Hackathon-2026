/**
 * js/buscador.js
 * -------------------------------------------------
 * Lógica de filtros, búsqueda y renderizado
 * dinámico de negocios/productos en index.html
 * -------------------------------------------------
 */

import { api, ApiError, formatMXN, toast } from './api.js';
import { geo, Geolocalizacion } from './geolocalizacion.js';

// ─── Estado global del buscador ───────────────────────────────────
const estado = {
  termino:    '',
  categoria:  '',
  lat:        null,
  lon:        null,
  pagina:     1,
  porPagina:  12,
  cargando:   false,
  solicitud:  0,
  resultados: [],
  total:      0,
};

// ─── Referencias DOM (se asignan en init) ─────────────────────────
let elSearchInput, elSearchBtn, elChips, elGrid, elResultCount,
    elBtnUbicacion, elBtnDemo, elUbicacion, elBtnVerMas;

// ═══════════════════════════════════════════════════════════════════
// INICIALIZACIÓN
// ═══════════════════════════════════════════════════════════════════
export async function initBuscador() {
  elSearchInput  = document.getElementById('search-input');
  elSearchBtn    = document.getElementById('search-btn');
  elChips        = document.getElementById('categoria-chips');
  elGrid         = document.getElementById('negocios-grid');
  elResultCount  = document.getElementById('result-count');
  elBtnUbicacion = document.getElementById('btn-ubicacion');
  elBtnDemo      = document.getElementById('btn-ubicacion-demo');
  elUbicacion    = document.getElementById('location-value');
  elBtnVerMas    = document.getElementById('btn-ver-mas');

  if (!elSearchInput || !elGrid) return; // No estamos en index.html

  _bindEventos();

  // Obtener ubicación e iniciar búsqueda
  mostrarSkeletons(true);
  try {
    const coords = await geo.obtenerPosicion({ solicitarPermiso: false });
    estado.lat = coords.lat;
    estado.lon = coords.lon;
    _actualizarBtnUbicacion(coords.source);
  } catch {
    // Continúa sin coordenadas
  }

  await buscar();
}

// ─── Binding de eventos ───────────────────────────────────────────
function _bindEventos() {
  // Búsqueda por texto
  elSearchInput.addEventListener('input', _debounce(() => {
    estado.termino = elSearchInput.value.trim();
    estado.pagina  = 1;
    buscar();
  }, 450));

  elSearchBtn?.addEventListener('click', () => {
    estado.termino = elSearchInput.value.trim();
    estado.pagina  = 1;
    buscar();
  });

  elSearchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      estado.termino = elSearchInput.value.trim();
      estado.pagina  = 1;
      buscar();
    }
  });

  // Chips de categoría
  elChips?.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip[data-categoria]');
    if (!chip) return;

    const val = chip.dataset.categoria;
    estado.categoria = (estado.categoria === val) ? '' : val;
    estado.pagina    = 1;

    elChips.querySelectorAll('.chip').forEach(c => {
      const activo = c.dataset.categoria === estado.categoria;
      c.classList.toggle('active', activo);
      c.setAttribute('aria-pressed', String(activo));
    });

    buscar();
  });

  // Actualizar ubicación
  elBtnUbicacion?.addEventListener('click', async () => {
    elBtnUbicacion.disabled = true;
    elBtnDemo.disabled = true;
    elUbicacion.textContent = 'Buscando ubicación…';
    try {
      const coords = await geo.actualizar();
      estado.lat = coords.lat;
      estado.lon = coords.lon;
      estado.pagina = 1;
      _actualizarBtnUbicacion(coords.source);
      await buscar();
    } catch {
      _actualizarBtnUbicacion(geo.posicionActual?.source ?? 'default');
      toast('No se pudo actualizar la ubicación.', 'danger');
    } finally {
      elBtnUbicacion.disabled = false;
      elBtnDemo.disabled = false;
    }
  });

  elBtnDemo?.addEventListener('click', async () => {
    const coords = geo.usarUbicacionDemo();
    estado.lat = coords.lat;
    estado.lon = coords.lon;
    estado.pagina = 1;
    _actualizarBtnUbicacion(coords.source);
    toast('Ubicación demo activada: Roma Norte, CDMX.', 'success');
    await buscar();
  });

  // Ver más (paginación)
  elBtnVerMas?.addEventListener('click', () => {
    if (estado.cargando) return;
    buscar(true); // append
  });
}

// ─── Función principal de búsqueda ───────────────────────────────
/**
 * @param {boolean} append - Si true, agrega resultados al grid existente
 */
export async function buscar(append = false) {
  const solicitud = ++estado.solicitud;
  const pagina = append ? estado.pagina + 1 : 1;
  estado.cargando = true;
  if (elBtnVerMas) elBtnVerMas.disabled = true;
  mostrarSkeletons(!append);

  try {
    const params = {
      action:    'buscar',
      q:         estado.termino    || undefined,
      categoria: estado.categoria  || undefined,
      lat:       estado.lat        ?? undefined,
      lon:       estado.lon        ?? undefined,
      orden: 'distancia',
      pagina,
      limite:    estado.porPagina,
    };

    const data = await api.get('buscar.php', params);
    if (solicitud !== estado.solicitud) return;
    estado.pagina = pagina;
    estado.resultados = append
      ? [...estado.resultados, ...(data.resultados ?? [])]
      : (data.resultados ?? []);
    estado.total = data.total ?? 0;

    renderResultados();
  } catch (err) {
    if (solicitud !== estado.solicitud) return;
    if (append) {
      toast('No se pudieron cargar más negocios.', 'danger');
      return;
    }
    if (elBtnVerMas) elBtnVerMas.style.display = 'none';
    if (err instanceof ApiError) {
      mostrarError(err.message);
    } else {
      mostrarError('Error inesperado. Intenta de nuevo.');
    }
  } finally {
    if (solicitud === estado.solicitud) {
      estado.cargando = false;
      if (elBtnVerMas) elBtnVerMas.disabled = false;
    }
  }
}

// ═══════════════════════════════════════════════════════════════════
// RENDERIZADO
// ═══════════════════════════════════════════════════════════════════
function renderResultados() {
  document.getElementById('titulo-resultados').textContent =
    estado.termino || estado.categoria ? 'Resultados' : 'Cerca de ti';
  if (!estado.resultados.length) {
    elGrid.innerHTML = `
      <div class="empty-state" style="grid-column:1/-1;">
        <div class="empty-state__icon">🔍</div>
        <div class="empty-state__title">Sin resultados</div>
        <p class="empty-state__desc">
          No encontramos negocios con ${estado.termino ? `"${_escape(estado.termino)}"` : 'esos filtros'}.
          Prueba con otra categoría o palabra.
        </p>
      </div>`;
    if (elResultCount) elResultCount.textContent = '0 negocios';
    if (elBtnVerMas) elBtnVerMas.style.display = 'none';
    return;
  }

  const fragment = document.createDocumentFragment();
  estado.resultados.forEach(negocio => {
    const card = crearNegocioCard(negocio);
    fragment.appendChild(card);
  });

  elGrid.replaceChildren(fragment);

  // Contador de resultados
  if (elResultCount) {
    elResultCount.textContent = `${estado.total} negocio${estado.total !== 1 ? 's' : ''} encontrado${estado.total !== 1 ? 's' : ''}`;
  }

  // Botón ver más
  if (elBtnVerMas) {
    const hayMas = estado.resultados.length < estado.total;
    elBtnVerMas.style.display = hayMas ? 'flex' : 'none';
    if (hayMas) {
      elBtnVerMas.textContent = `Ver más (${estado.total - estado.resultados.length} restantes)`;
    }
  }
}

/**
 * Crea el elemento DOM de una tarjeta de negocio.
 * @param {Object} negocio
 * @returns {HTMLElement}
 */
function crearNegocioCard(negocio) {
  const card = document.createElement('article');
  card.className = 'negocio-card';
  card.setAttribute('role', 'button');
  card.tabIndex = 0;
  card.setAttribute('aria-label', `Ver negocio ${negocio.nombre}`);
  const distancia = negocio.distancia_km != null
    ? Geolocalizacion.formatDistancia(Number(negocio.distancia_km)) : '';
  const portadas = {
    comercio: 'papeleria',
    bazar: 'bazar',
    joyeria: 'joyeria',
    estetica: 'estetica',
    belleza: 'estetica',
    boutique: 'boutique',
    ropa: 'boutique',
    barberia: 'barberia',
    perfumeria: 'perfumeria',
    taller: 'taller',
    tecnico: 'tecnologia',
    salud: 'farmacia',
    otro: 'farmacia',
  };
  const portadaProvisional = `imagenes/negocios/${portadas[negocio.categoria?.toLowerCase()] ?? 'papeleria'}.jpg`;
  const verificado = negocio.verificado
    ? '<span class="verificado-icon" title="Negocio verificado">✓</span>' : '';
  const logo = negocio.logo_url
    ? `<img src="${_escape(negocio.logo_url)}" alt="Logo ${_escape(negocio.nombre)}" class="negocio-card__logo" loading="lazy">`
    : `<div class="negocio-card__logo" style="display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:var(--color-primary-light);">${_categoriaEmoji(negocio.categoria)}</div>`;
  card.innerHTML = `
    <div class="negocio-card__cover">
      <img class="negocio-card__foto" alt="" loading="lazy">
    </div>
    <div class="negocio-card__body">
      <div class="negocio-card__header">
        ${logo}
        <div class="negocio-card__info">
          <div class="negocio-card__nombre">${_escape(negocio.nombre)} ${verificado}</div>
          <div class="negocio-card__categoria">${_escape(_formatearCategoria(negocio.categoria))}</div>
        </div>
      </div>
      ${negocio.descripcion ? `<p class="negocio-card__desc">${_escape(negocio.descripcion)}</p>` : ''}
      <div class="negocio-card__meta">
        ${distancia ? `<span class="negocio-card__meta-item">📍 <span class="negocio-card__distancia">${distancia}</span></span>` : ''}
        ${negocio.ciudad ? `<span class="negocio-card__meta-item">🏙 ${_escape(negocio.ciudad)}</span>` : ''}
      </div>
      <div class="negocio-card__footer"><span class="text-sm text-muted">Ver catálogo →</span></div>
    </div>`;
  const foto = card.querySelector('.negocio-card__foto');
  const imagenes = [...new Set([negocio.logo_url, negocio.imagen_producto_url, portadaProvisional].filter(Boolean))];
  foto.addEventListener('error', () => {
    const siguiente = imagenes.shift();
    if (siguiente) foto.src = siguiente;
    else foto.remove();
  });
  foto.src = imagenes.shift();
  const navegar = () => { window.location.href = `negocio.html?id=${encodeURIComponent(negocio.id)}`; };
  card.addEventListener('click', navegar);
  card.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      navegar();
    }
  });
  return card;
}
function mostrarSkeletons(mostrar) {
  if (!elGrid) return;

  if (mostrar) {
    elGrid.innerHTML = Array.from({ length: 6 }).map(() => `
      <div class="negocio-card" style="pointer-events:none;">
        <div class="negocio-card__cover" style="background:var(--color-surface-alt);animation:pulse 1.5s infinite;">
        </div>
        <div class="negocio-card__body" style="gap:12px;">
          <div style="height:14px;width:60%;border-radius:4px;background:var(--color-surface-alt);animation:pulse 1.5s infinite;"></div>
          <div style="height:12px;width:40%;border-radius:4px;background:var(--color-surface-alt);animation:pulse 1.5s infinite;"></div>
          <div style="height:12px;width:80%;border-radius:4px;background:var(--color-surface-alt);animation:pulse 1.5s infinite;"></div>
        </div>
      </div>`).join('');
  }
}

function mostrarError(msg) {
  if (!elGrid) return;
  elGrid.innerHTML = `
    <div class="alert alert--danger" style="grid-column:1/-1;">
      ⚠️ ${_escape(msg)}
    </div>`;
}

// ─── Helpers privados ─────────────────────────────────────────────
function _actualizarBtnUbicacion(source) {
  const etiquetas = {
    gps: 'Ubicación actual',
    manual: 'Ubicación manual',
    default: 'Centro de CDMX',
    demo: 'Roma Norte, CDMX',
  };
  if (elUbicacion) elUbicacion.textContent = etiquetas[source] ?? 'Elegir ubicación';
}

function _escape(str) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function _categoriaEmoji(cat) {
  const mapa = {
    estetica:   '💇',
    barberia:   '✂️',
    perfumeria: '🌸',
    bazar:      '🛍️',
    boutique:   '🧥',
    taller:     '🔧',
    tecnico:    '💻',
    ropa:       '👗',
    joyeria:    '💍',
    comercio:   '✏️',
    otro:       '🏪',
  };
  return mapa[cat?.toLowerCase()] ?? '🏪';
}

function _formatearCategoria(categoria) {
  const etiquetas = {
    estetica: 'Estética',
    barberia: 'Barbería',
    perfumeria: 'Perfumería',
    bazar: 'Bazar',
    boutique: 'Boutique',
    taller: 'Taller',
    tecnico: 'Servicio técnico',
    ropa: 'Ropa',
    joyeria: 'Joyería',
    comercio: 'Comercio local',
    restaurante: 'Restaurante',
    salud: 'Salud',
    automotriz: 'Servicio automotriz',
    mascotas: 'Mascotas',
    hogar: 'Servicios para el hogar',
    cafeteria: 'Cafetería',
    belleza: 'Belleza',
    entretenimiento: 'Entretenimiento',
    limpieza: 'Servicio de limpieza',
    otro: 'Otro comercio',
  };
  return etiquetas[categoria?.toLowerCase()] ?? categoria ?? 'Comercio local';
}

function _debounce(fn, ms) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
}
