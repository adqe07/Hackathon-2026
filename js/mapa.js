import { Geolocalizacion } from './geolocalizacion.js';

const TILE_SIZE = 256;
const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const MIN_ZOOM = 3;
const MAX_ZOOM = 18;

/** Mapa interactivo de Mova con motor Web Mercator propio y cartografía OSM. */
export class MapaMova {
  constructor(contenedor, opciones = {}) {
    this.contenedor = typeof contenedor === 'string' ? document.querySelector(contenedor) : contenedor;
    this.onSeleccionar = opciones.onSeleccionar ?? null;
    this.modo = opciones.modo ?? 'explorador';
    this.centro = { lat: 19.4326, lon: -99.1332 };
    this.zoom = 13;
    this.usuario = null;
    this.negocios = [];
    this.arrastre = null;
    this.framePendiente = null;
    this.resizeObserver = null;
  }

  render({ usuario = null, negocios = [] } = {}) {
    if (!this.contenedor) return;
    this.usuario = normalizarPunto(usuario);
    this.negocios = negocios.map(normalizarNegocio).filter(Boolean);
    const puntos = [...this.negocios, ...(this.usuario ? [this.usuario] : [])];

    if (!puntos.length) {
      this.contenedor.innerHTML = '<div class="mapa-mova__vacio">Activa tu ubicación para visualizar negocios cercanos.</div>';
      return;
    }

    this.contenedor.innerHTML = `
      <div class="mapa-mova__lienzo">
        <div class="mapa-mova__viewport" tabindex="0" role="application" aria-label="Mapa interactivo. Arrastra para desplazarte y usa los controles para cambiar el zoom.">
          <div class="mapa-mova__tiles" aria-hidden="true"></div>
          <div class="mapa-mova__marcadores"></div>
        </div>
        <div class="mapa-mova__controles" aria-label="Controles del mapa">
          <button type="button" data-map-zoom="in" aria-label="Acercar mapa">+</button>
          <button type="button" data-map-zoom="out" aria-label="Alejar mapa">−</button>
          <button type="button" data-map-fit aria-label="Mostrar todos los marcadores">◎</button>
        </div>
        <div class="mapa-mova__leyenda" aria-hidden="true"><span><i class="mapa-mova__dot mapa-mova__dot--usuario"></i>Tu ubicación</span><span><i class="mapa-mova__dot mapa-mova__dot--negocio"></i>Negocio</span></div>
        <div class="mapa-mova__atribucion"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap</a> · <a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noopener noreferrer">Reportar un problema</a></div>
      </div>
      <div class="mapa-mova__lista" aria-label="Negocios representados en el mapa"></div>`;

    this.viewport = this.contenedor.querySelector('.mapa-mova__viewport');
    this.tilesEl = this.contenedor.querySelector('.mapa-mova__tiles');
    this.markersEl = this.contenedor.querySelector('.mapa-mova__marcadores');
    this.listaEl = this.contenedor.querySelector('.mapa-mova__lista');
    this._ajustarVista();
    this._vincularInteraccion();
    this._dibujar();

    this.resizeObserver?.disconnect();
    this.resizeObserver = new ResizeObserver(() => this._dibujar());
    this.resizeObserver.observe(this.viewport);
  }

  _ajustarVista() {
    const puntos = [...this.negocios, ...(this.usuario ? [this.usuario] : [])];
    this.centro = {
      lat: puntos.reduce((suma, punto) => suma + punto.lat, 0) / puntos.length,
      lon: puntos.reduce((suma, punto) => suma + punto.lon, 0) / puntos.length,
    };
    if (puntos.length === 1) { this.zoom = 15; return; }
    const ancho = Math.max(this.viewport?.clientWidth || 760, 320) - 120;
    const alto = Math.max(this.viewport?.clientHeight || 360, 260) - 120;
    for (let zoom = MAX_ZOOM; zoom >= MIN_ZOOM; zoom--) {
      const coords = puntos.map((punto) => proyectar(punto.lat, punto.lon, zoom));
      const spanX = Math.max(...coords.map((p) => p.x)) - Math.min(...coords.map((p) => p.x));
      const spanY = Math.max(...coords.map((p) => p.y)) - Math.min(...coords.map((p) => p.y));
      if (spanX <= ancho && spanY <= alto) { this.zoom = zoom; return; }
    }
    this.zoom = MIN_ZOOM;
  }

  _dibujar() {
    if (!this.viewport) return;
    const ancho = this.viewport.clientWidth;
    const alto = this.viewport.clientHeight;
    if (!ancho || !alto) return;
    const centroPx = proyectar(this.centro.lat, this.centro.lon, this.zoom);
    this._dibujarTiles(ancho, alto, centroPx);
    this._dibujarMarcadores(ancho, alto, centroPx);
    this._dibujarLista();
  }

  _dibujarTiles(ancho, alto, centroPx) {
    const total = 2 ** this.zoom;
    const minX = Math.floor((centroPx.x - ancho / 2) / TILE_SIZE);
    const maxX = Math.floor((centroPx.x + ancho / 2) / TILE_SIZE);
    const minY = Math.max(0, Math.floor((centroPx.y - alto / 2) / TILE_SIZE));
    const maxY = Math.min(total - 1, Math.floor((centroPx.y + alto / 2) / TILE_SIZE));
    const fragmento = document.createDocumentFragment();
    for (let y = minY; y <= maxY; y++) {
      for (let x = minX; x <= maxX; x++) {
        const tileX = ((x % total) + total) % total;
        const img = document.createElement('img');
        img.className = 'mapa-mova__tile';
        img.alt = '';
        img.draggable = false;
        img.loading = 'eager';
        img.src = TILE_URL.replace('{z}', this.zoom).replace('{x}', tileX).replace('{y}', y);
        img.style.left = `${x * TILE_SIZE - centroPx.x + ancho / 2}px`;
        img.style.top = `${y * TILE_SIZE - centroPx.y + alto / 2}px`;
        fragmento.appendChild(img);
      }
    }
    this.tilesEl.replaceChildren(fragmento);
  }

  _dibujarMarcadores(ancho, alto, centroPx) {
    const conDistancia = this.usuario
      ? this.negocios.map((punto) => ({ ...punto, distancia: Geolocalizacion.distanciaKm(this.usuario.lat, this.usuario.lon, punto.lat, punto.lon) })).sort((a, b) => a.distancia - b.distancia)
      : [...this.negocios];
    this.ordenados = conDistancia;
    const cercanoId = conDistancia[0]?.id;
    const posiciones = new Map(conDistancia.map((punto, indice) => [String(punto.id), indice + 1]));
    const fragmento = document.createDocumentFragment();

    this.negocios.forEach((punto) => {
      const posicion = posicionEnPantalla(punto, this.zoom, centroPx, ancho, alto);
      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = `mapa-mova__marcador mapa-mova__marcador--negocio${String(punto.id) === String(cercanoId) ? ' mapa-mova__marcador--cercano' : ''}`;
      boton.style.left = `${posicion.x}px`;
      boton.style.top = `${posicion.y}px`;
      boton.dataset.mapaNegocio = punto.id;
      boton.setAttribute('aria-label', `${punto.nombre}${String(punto.id) === String(cercanoId) ? ', negocio más cercano' : ''}`);
      boton.title = punto.nombre;
      boton.innerHTML = `<span>${posiciones.get(String(punto.id))}</span>`;
      boton.addEventListener('click', () => this._seleccionar(punto));
      fragmento.appendChild(boton);
    });

    if (this.usuario) {
      const posicion = posicionEnPantalla(this.usuario, this.zoom, centroPx, ancho, alto);
      const usuario = document.createElement('div');
      usuario.className = 'mapa-mova__marcador mapa-mova__marcador--usuario';
      usuario.style.left = `${posicion.x}px`;
      usuario.style.top = `${posicion.y}px`;
      usuario.setAttribute('aria-label', 'Tu ubicación');
      usuario.innerHTML = '<span></span>';
      fragmento.appendChild(usuario);
    }
    this.markersEl.replaceChildren(fragmento);
  }

  _dibujarLista() {
    const cercanoId = this.ordenados?.[0]?.id;
    this.listaEl.innerHTML = (this.ordenados ?? []).map((punto, indice) => {
      const detalles = [String(punto.id) === String(cercanoId) ? 'Más cercano' : punto.categoria, Number.isFinite(punto.distancia) ? Geolocalizacion.formatDistancia(punto.distancia) : '', punto.direccion].filter(Boolean).map(escapeHtml).join(' · ');
      return `<button class="mapa-mova__item${String(punto.id) === String(cercanoId) ? ' mapa-mova__item--cercano' : ''}" type="button" data-lista-id="${Number(punto.id)}"><span class="mapa-mova__numero">${indice + 1}</span><span><strong>${escapeHtml(punto.nombre)}</strong><small>${detalles}</small></span></button>`;
    }).join('') || '<span class="text-sm text-muted">No hay negocios para mostrar.</span>';
    this.listaEl.querySelectorAll('[data-lista-id]').forEach((boton) => boton.addEventListener('click', () => {
      const punto = this.negocios.find((negocio) => Number(negocio.id) === Number(boton.dataset.listaId));
      if (punto) this._seleccionar(punto);
    }));
  }

  _vincularInteraccion() {
    this.contenedor.querySelector('[data-map-zoom="in"]').addEventListener('click', () => this._cambiarZoom(1));
    this.contenedor.querySelector('[data-map-zoom="out"]').addEventListener('click', () => this._cambiarZoom(-1));
    this.contenedor.querySelector('[data-map-fit]').addEventListener('click', () => { this._ajustarVista(); this._dibujar(); });
    this.viewport.addEventListener('wheel', (evento) => { evento.preventDefault(); this._cambiarZoom(evento.deltaY < 0 ? 1 : -1); }, { passive: false });
    this.viewport.addEventListener('keydown', (evento) => {
      const pasos = { ArrowLeft: [-80, 0], ArrowRight: [80, 0], ArrowUp: [0, -80], ArrowDown: [0, 80] };
      if (pasos[evento.key]) { evento.preventDefault(); this._desplazar(...pasos[evento.key]); }
      if (evento.key === '+' || evento.key === '=') this._cambiarZoom(1);
      if (evento.key === '-') this._cambiarZoom(-1);
    });
    this.viewport.addEventListener('pointerdown', (evento) => {
      if (evento.target.closest('.mapa-mova__marcador')) return;
      this.arrastre = { x: evento.clientX, y: evento.clientY };
      this.viewport.setPointerCapture(evento.pointerId);
      this.viewport.classList.add('is-dragging');
    });
    this.viewport.addEventListener('pointermove', (evento) => {
      if (!this.arrastre) return;
      const dx = this.arrastre.x - evento.clientX;
      const dy = this.arrastre.y - evento.clientY;
      this.arrastre = { x: evento.clientX, y: evento.clientY };
      this._desplazar(dx, dy);
    });
    const terminar = () => { this.arrastre = null; this.viewport.classList.remove('is-dragging'); };
    this.viewport.addEventListener('pointerup', terminar);
    this.viewport.addEventListener('pointercancel', terminar);
  }

  _cambiarZoom(delta) {
    const nuevo = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, this.zoom + delta));
    if (nuevo === this.zoom) return;
    this.zoom = nuevo;
    this._dibujar();
  }

  _desplazar(dx, dy) {
    const centroPx = proyectar(this.centro.lat, this.centro.lon, this.zoom);
    this.centro = desproyectar(centroPx.x + dx, centroPx.y + dy, this.zoom);
    if (this.framePendiente) return;
    this.framePendiente = requestAnimationFrame(() => {
      this.framePendiente = null;
      this._dibujar();
    });
  }

  _seleccionar(negocio) {
    if (this.onSeleccionar) this.onSeleccionar(negocio);
    else window.location.href = `negocio.html?id=${encodeURIComponent(negocio.id)}`;
  }
}

function proyectar(lat, lon, zoom) {
  const escala = TILE_SIZE * (2 ** zoom);
  const seno = Math.min(0.9999, Math.max(-0.9999, Math.sin(lat * Math.PI / 180)));
  return { x: (lon + 180) / 360 * escala, y: (0.5 - Math.log((1 + seno) / (1 - seno)) / (4 * Math.PI)) * escala };
}

function desproyectar(x, y, zoom) {
  const escala = TILE_SIZE * (2 ** zoom);
  const lon = x / escala * 360 - 180;
  const n = Math.PI - 2 * Math.PI * y / escala;
  return { lat: 180 / Math.PI * Math.atan(Math.sinh(n)), lon };
}

function posicionEnPantalla(punto, zoom, centro, ancho, alto) {
  const px = proyectar(punto.lat, punto.lon, zoom);
  return { x: px.x - centro.x + ancho / 2, y: px.y - centro.y + alto / 2 };
}

function normalizarPunto(punto) {
  const lat = Number(punto?.lat), lon = Number(punto?.lon);
  return Number.isFinite(lat) && Number.isFinite(lon) ? { ...punto, lat, lon } : null;
}

function normalizarNegocio(negocio) {
  const lat = Number(negocio?.latitud ?? negocio?.lat), lon = Number(negocio?.longitud ?? negocio?.lon);
  if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
  return { ...negocio, lat, lon, nombre: negocio.nombre || 'Negocio' };
}

function escapeHtml(valor) {
  return String(valor ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
