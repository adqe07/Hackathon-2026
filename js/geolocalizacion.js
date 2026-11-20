/**
 * js/geolocalizacion.js
 * -------------------------------------------------
 * Captura de coordenadas GPS del navegador y
 * fallback manual si el usuario deniega el permiso.
 * -------------------------------------------------
 * Uso:
 *   import { Geolocalizacion } from './geolocalizacion.js';
 *   const geo = new Geolocalizacion();
 *   const coords = await geo.obtenerPosicion();
 *   // { lat: 19.419, lon: -99.162, source: 'gps'|'manual'|'default' }
 */

// Coordenadas por defecto: Centro de CDMX (Zócalo)
const COORDS_DEFAULT = { lat: 19.4326, lon: -99.1332, source: 'default' };
const COORDS_DEMO_ROMA_NORTE = { lat: 19.41944, lon: -99.16266, source: 'demo' };

// Clave de localStorage para guardar la última posición conocida
const STORAGE_KEY = 'mova_ultima_posicion';

export class Geolocalizacion {
  constructor() {
    /** @type {{ lat: number, lon: number, source: string } | null} */
    this._posicion = null;
    this._listeners = [];
  }

  // ── Obtener posición (principal) ─────────────────────────────────
  /**
   * Intenta obtener la posición GPS del navegador.
   * Si no está disponible o el usuario deniega, ofrece fallback.
   *
   * @param {{ forcePrompt?: boolean, solicitarPermiso?: boolean }} [opciones]
   * @returns {Promise<{ lat: number, lon: number, source: string }>}
   */
  async obtenerPosicion({ forcePrompt = false, solicitarPermiso = true } = {}) {
    // 1. Usar posición ya cargada en esta instancia
    if (this._posicion && !forcePrompt) {
      return this._posicion;
    }

    // 2. Intentar desde localStorage (sesión anterior)
    if (!forcePrompt) {
      const guardada = this._leerStorage();
      if (guardada) {
        this._posicion = guardada;
        return guardada;
      }
    }

    if (!solicitarPermiso) return COORDS_DEFAULT;

    // 3. Intentar GPS del navegador
    if ('geolocation' in navigator) {
      try {
        const coords = await this._pedirGPS();
        this._posicion = coords;
        this._guardarStorage(coords);
        this._emitir(coords);
        return coords;
      } catch (err) {
        console.warn('[Geo] GPS no disponible:', err.message);
        // Si el permiso fue denegado permanentemente, ir a fallback manual
        if (err.code === GeolocationPositionError.PERMISSION_DENIED) {
          return this._fallbackManual();
        }
        // En otros errores (timeout, unavailable) usar default o última conocida
        return this._posicion ?? COORDS_DEFAULT;
      }
    }

    // 4. API no disponible
    return this._fallbackManual();
  }

  // ── GPS del navegador ────────────────────────────────────────────
  /**
   * @returns {Promise<{ lat: number, lon: number, source: 'gps' }>}
   */
  _pedirGPS() {
    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({
          lat: parseFloat(pos.coords.latitude.toFixed(8)),
          lon: parseFloat(pos.coords.longitude.toFixed(8)),
          source: 'gps',
          precision: pos.coords.accuracy, // metros
        }),
        (err) => reject(err),
        {
          enableHighAccuracy: true,
          timeout: 10_000,          // 10 segundos máximo
          maximumAge: 5 * 60_000,  // Usar cache de hasta 5 minutos
        }
      );
    });
  }

  // ── Fallback manual ──────────────────────────────────────────────
  /**
   * Muestra un modal / banner para que el usuario escriba su ciudad
   * o dirección, y hace geocoding simplificado con OpenStreetMap Nominatim.
   * Si el usuario cancela, usa COORDS_DEFAULT.
   * @returns {Promise<{ lat: number, lon: number, source: string }>}
   */
  async _fallbackManual() {
    return new Promise((resolve) => {
      // Crear banner de solicitud
      const banner = document.createElement('div');
      banner.className = 'geo-fallback-banner';
      banner.innerHTML = `
        <div class="geo-fallback-banner__inner">
          <span class="geo-fallback-banner__icon">📍</span>
          <div class="geo-fallback-banner__body">
            <strong>¿Dónde te encuentras?</strong>
            <p>Escribe tu colonia o ciudad para mostrarte negocios cercanos.</p>
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
              <input
                type="text"
                id="geo-input"
                class="form-control"
                placeholder="Ej: Roma Norte, CDMX"
                style="flex:1;min-width:200px;"
              />
              <button id="geo-buscar-btn" class="btn btn--primary btn--sm">Buscar</button>
              <button id="geo-cancelar-btn" class="btn btn--ghost btn--sm">Usar defecto</button>
            </div>
            <p id="geo-error-msg" style="color:var(--color-danger);font-size:0.75rem;margin-top:4px;display:none;"></p>
          </div>
        </div>
      `;

      // Estilos del banner inline para que no dependa de que CSS ya cargó
      Object.assign(banner.style, {
        position: 'fixed',
        bottom: '0',
        left: '0',
        right: '0',
        zIndex: '8000',
        background: 'var(--color-surface, #fff)',
        borderTop: '1px solid var(--color-border, #E2E5F0)',
        boxShadow: '0 -4px 20px rgba(0,0,0,0.1)',
        padding: '16px',
        fontFamily: 'var(--font-sans, system-ui)',
        animation: 'slideUp 0.3s ease',
      });

      document.body.appendChild(banner);

      const input  = banner.querySelector('#geo-input');
      const btnBuscar   = banner.querySelector('#geo-buscar-btn');
      const btnCancelar = banner.querySelector('#geo-cancelar-btn');
      const errMsg = banner.querySelector('#geo-error-msg');
      input.focus();

      const limpiarYResolver = (coords) => {
        banner.remove();
        this._posicion = coords;
        this._guardarStorage(coords);
        this._emitir(coords);
        resolve(coords);
      };

      btnCancelar.addEventListener('click', () => {
        limpiarYResolver({ ...COORDS_DEFAULT });
      });

      const buscar = async () => {
        const texto = input.value.trim();
        if (!texto) {
          errMsg.textContent = 'Escribe una dirección o colonia.';
          errMsg.style.display = 'block';
          return;
        }

        btnBuscar.disabled = true;
        btnBuscar.textContent = '…';
        errMsg.style.display = 'none';

        try {
          const coords = await this._geocodificar(texto);
          limpiarYResolver(coords);
        } catch (e) {
          errMsg.textContent = 'No se encontró esa dirección. Intenta con otra.';
          errMsg.style.display = 'block';
          btnBuscar.disabled = false;
          btnBuscar.textContent = 'Buscar';
        }
      };

      btnBuscar.addEventListener('click', buscar);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') buscar();
      });
    });
  }

  // ── Geocoding con Nominatim (OSM, sin API key) ───────────────────
  /**
   * @param {string} query  Dirección en texto libre
   * @returns {Promise<{ lat: number, lon: number, source: 'manual' }>}
   */
  async _geocodificar(query) {
    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`;
    const res = await fetch(url, {
      headers: { 'Accept-Language': 'es' },
    });

    if (!res.ok) throw new Error('Error Nominatim');

    const data = await res.json();
    if (!data.length) throw new Error('Sin resultados');

    return {
      lat: parseFloat(parseFloat(data[0].lat).toFixed(8)),
      lon: parseFloat(parseFloat(data[0].lon).toFixed(8)),
      source: 'manual',
      displayName: data[0].display_name,
    };
  }

  // ── Persistencia en localStorage ─────────────────────────────────
  _guardarStorage(coords) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        ...coords,
        ts: Date.now(),
      }));
    } catch { /* Modo privado u otros errores */ }
  }

  _leerStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      // Expirar posición guardada después de 30 minutos
      const TREINTA_MIN = 30 * 60_000;
      if (Date.now() - data.ts > TREINTA_MIN) {
        localStorage.removeItem(STORAGE_KEY);
        return null;
      }
      return { lat: data.lat, lon: data.lon, source: data.source };
    } catch {
      return null;
    }
  }

  // ── Eventos (Observer pattern) ────────────────────────────────────
  /**
   * Registra un listener que se llama cada vez que cambia la posición.
   * @param {Function} fn
   */
  onChange(fn) {
    this._listeners.push(fn);
  }

  _emitir(coords) {
    this._listeners.forEach(fn => fn(coords));
  }

  // ── Utilidades públicas ───────────────────────────────────────────
  /**
   * Devuelve la posición cacheada sin hacer solicitudes nuevas.
   * Puede ser null si no se ha obtenido ninguna.
   * @returns {{ lat: number, lon: number, source: string } | null}
   */
  get posicionActual() {
    return this._posicion;
  }

  /**
   * Fuerza re-pedir el GPS (útil para botón "actualizar ubicación").
   * @returns {Promise<{ lat: number, lon: number, source: string }>}
   */
  actualizar() {
    return this.obtenerPosicion({ forcePrompt: true });
  }

  /** Fija una coordenada reproducible para probar los negocios semilla. */
  usarUbicacionDemo() {
    const coords = { ...COORDS_DEMO_ROMA_NORTE };
    this._posicion = coords;
    this._guardarStorage(coords);
    this._emitir(coords);
    return coords;
  }

  /**
   * Calcula la distancia en km entre dos coordenadas (Haversine JS).
   * @param {number} lat1
   * @param {number} lon1
   * @param {number} lat2
   * @param {number} lon2
   * @returns {number} Kilómetros
   */
  static distanciaKm(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a =
      Math.sin(dLat / 2) ** 2 +
      Math.cos(lat1 * Math.PI / 180) *
      Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.asin(Math.sqrt(a));
  }

  /**
   * Formatea la distancia en texto legible.
   * @param {number} km
   * @returns {string}  "320 m" | "1.4 km"
   */
  static formatDistancia(km) {
    if (km < 1) return `${Math.round(km * 1000)} m`;
    return `${km.toFixed(1)} km`;
  }
}

// Instancia singleton global para toda la app
export const geo = new Geolocalizacion();
