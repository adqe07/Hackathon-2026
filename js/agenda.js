/**
 * js/agenda.js
 * -------------------------------------------------
 * Calendario de selección de fecha/hora,
 * cálculo de slots disponibles, flujo de anticipo
 * y generación/validación del PIN de 4 dígitos.
 * -------------------------------------------------
 */

import { api, ApiError, formatMXN, formatFecha, toast, btnLoading } from './api.js';

// ═══════════════════════════════════════════════════════════════════
// CLASE PRINCIPAL: AgendaCalendario
// ═══════════════════════════════════════════════════════════════════
export class AgendaCalendario {
  /**
   * @param {Object} opts
   * @param {number}      opts.negocioId
   * @param {number}      opts.servicioId
   * @param {number}      opts.duracionMinutos
   * @param {number}      opts.precioEstimado
   * @param {number}      opts.montoAnticipo
   * @param {string}      opts.nombreServicio
   * @param {HTMLElement} opts.contenedor     — Donde se monta el componente
   * @param {Function}    [opts.onCitaCreada] — Callback al crear cita exitosamente
   */
  constructor(opts) {
    this.negocioId       = opts.negocioId;
    this.servicioId      = opts.servicioId;
    this.duracion        = opts.duracionMinutos;
    this.precioEstimado  = opts.precioEstimado;
    this.montoAnticipo   = opts.montoAnticipo;
    this.nombreServicio  = opts.nombreServicio;
    this.contenedor      = opts.contenedor;
    this.onCitaCreada    = opts.onCitaCreada ?? (() => {});

    this._fechaSeleccionada = null;
    this._slotSeleccionado  = null;
    this._diasDisponibles   = 14; // Muestra hasta 2 semanas

    this._render();
  }

  // ── Renderizado inicial ─────────────────────────────────────────
  _render() {
    this.contenedor.innerHTML = `
      <div class="agenda-wrapper">
        <!-- Selector de día -->
        <h4 style="margin-bottom:var(--space-3);">1. Elige el día</h4>
        <div class="day-selector" id="day-selector"></div>

        <!-- Selector de horario -->
        <h4 style="margin:var(--space-5) 0 var(--space-3);">2. Elige el horario</h4>
        <div id="slots-container">
          <p class="text-muted text-sm">Selecciona un día para ver los horarios disponibles.</p>
        </div>

        <!-- Resumen y anticipo -->
        <div id="agenda-resumen" style="display:none;margin-top:var(--space-5);">
          <hr class="divider">
          <h4 style="margin-bottom:var(--space-4);">3. Confirma tu cita</h4>
          <div class="agenda-resumen__card">
            <div style="font-weight:var(--weight-bold);font-size:var(--text-lg);" id="res-servicio"></div>
            <div class="agenda-resumen__dato"><svg class="agenda-resumen__icono" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg><span id="res-fecha"></span></div>
            <div class="agenda-resumen__dato"><svg class="agenda-resumen__icono" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Duración: <strong>${this.duracion} min</strong></span></div>
            <hr style="border:none;border-top:1px solid var(--color-border);">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span class="text-sm">Precio estimado:</span>
              <span style="font-weight:var(--weight-semibold);" id="res-precio"></span>
            </div>
            <div class="agenda-resumen__anticipo">
              <span class="text-sm font-semibold">Anticipo a pagar ahora:</span>
              <span id="res-anticipo"></span>
            </div>
            <p class="text-xs text-muted agenda-resumen__dato">
              <svg class="agenda-resumen__icono" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg><span>El anticipo queda en custodia. Se devuelve si cancelas antes del límite configurado por el negocio.</span>
            </p>
          </div>

          <div id="agenda-error" class="alert alert--danger" style="display:none;"></div>

          <button id="btn-agendar" class="btn btn--primary btn--full btn--lg">
            Pagar anticipo y agendar
          </button>
        </div>
      </div>`;

    this._renderDias();
    this._bindEventos();
  }

  // ── Renderizar días disponibles ─────────────────────────────────
  _renderDias() {
    const container = this.contenedor.querySelector('#day-selector');
    container.innerHTML = '';
    const hoy = new Date();

    for (let i = 0; i < this._diasDisponibles; i++) {
      const fecha = new Date(hoy);
      fecha.setDate(hoy.getDate() + i);
      const esDomingo = fecha.getDay() === 0;

      const btn = document.createElement('button');
      btn.className = `day-btn${esDomingo ? ' day-btn--disabled' : ''}`;
      btn.disabled = esDomingo;
      btn.dataset.fecha = _isoDate(fecha);

      const dow = fecha.toLocaleDateString('es-MX', { weekday: 'short' });
      const num = fecha.getDate();
      const mes = fecha.toLocaleDateString('es-MX', { month: 'short' }).replace('.', '');
      const anio = fecha.getFullYear();
      btn.setAttribute('aria-label', fecha.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
      btn.innerHTML = `
        <span class="day-btn__dow">${dow}</span>
        <span class="day-btn__num">${num}</span>
        <span class="day-btn__mes">${mes} ${anio}</span>`;

      container.appendChild(btn);
    }
  }

  // ── Binding de eventos ──────────────────────────────────────────
  _bindEventos() {
    // Selección de día
    this.contenedor.querySelector('#day-selector').addEventListener('click', async (e) => {
      const btn = e.target.closest('.day-btn');
      if (!btn || btn.disabled) return;

      this.contenedor.querySelectorAll('.day-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      this._fechaSeleccionada = btn.dataset.fecha;
      this._slotSeleccionado  = null;
      this.contenedor.querySelector('#agenda-resumen').style.display = 'none';

      await this._cargarSlots(this._fechaSeleccionada);
    });

    // Selección de slot
    this.contenedor.querySelector('#slots-container').addEventListener('click', (e) => {
      const btn = e.target.closest('.slot-btn:not(.ocupado):not(:disabled)');
      if (!btn) return;

      this.contenedor.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      this._slotSeleccionado = btn.dataset.hora;
      this._mostrarResumen();
    });

    // Botón agendar
    this.contenedor.addEventListener('click', async (e) => {
      if (e.target.closest('#btn-agendar')) {
        await this._crearCita();
      }
    });
  }

  // ── Cargar slots disponibles del servidor ───────────────────────
  async _cargarSlots(fecha) {
    const container = this.contenedor.querySelector('#slots-container');
    container.innerHTML = `<p class="text-muted text-sm"><span class="spinner"></span> Cargando horarios…</p>`;

    try {
      const data = await api.get('citas.php', {
        action:      'slots',
        negocio_id:  this.negocioId,
        servicio_id: this.servicioId,
        fecha,
      });

      const slots = data.slots ?? [];
      this._renderSlots(container, slots);
    } catch (err) {
      container.innerHTML = `<p class="text-sm" style="color:var(--color-danger);">
        No se pudieron cargar los horarios. Intenta de nuevo.
      </p>`;
    }
  }

  // ── Renderizar slots ────────────────────────────────────────────
  _renderSlots(container, slots) {
    if (!slots.length) {
      container.innerHTML = `<p class="text-sm text-muted">
        No hay horarios disponibles para este día. Selecciona otro.
      </p>`;
      return;
    }

    const grid = document.createElement('div');
    grid.className = 'slot-grid';

    slots.forEach(slot => {
      const btn = document.createElement('button');
      btn.className = `slot-btn${slot.disponible ? '' : ' ocupado'}`;
      btn.disabled  = !slot.disponible;
      btn.dataset.hora = slot.hora_inicio;
      btn.textContent = _formatHora(slot.hora_inicio);
      grid.appendChild(btn);
    });

    container.innerHTML = '';
    container.appendChild(grid);
  }

  // ── Mostrar resumen de la cita ──────────────────────────────────
  _mostrarResumen() {
    if (!this._fechaSeleccionada || !this._slotSeleccionado) return;

    const resumen = this.contenedor.querySelector('#agenda-resumen');
    this.contenedor.querySelector('#res-servicio').textContent = this.nombreServicio;
    this.contenedor.querySelector('#res-fecha').textContent =
      _formatFechaHora(this._fechaSeleccionada, this._slotSeleccionado);
    this.contenedor.querySelector('#res-precio').textContent   = formatMXN(this.precioEstimado);
    this.contenedor.querySelector('#res-anticipo').textContent = formatMXN(this.montoAnticipo);

    resumen.style.display = 'block';
    resumen.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ── Crear cita en el servidor ───────────────────────────────────
  async _crearCita() {
    if (!this._fechaSeleccionada || !this._slotSeleccionado) return;

    const btn      = this.contenedor.querySelector('#btn-agendar');
    const errEl    = this.contenedor.querySelector('#agenda-error');
    errEl.style.display = 'none';
    btnLoading(btn, true);

    try {
      const data = await api.post('citas.php', { action: 'crear' }, {
        servicio_id:       this.servicioId,
        negocio_id:        this.negocioId,
        fecha_hora_inicio: `${this._fechaSeleccionada} ${this._slotSeleccionado}:00`,
      });

      toast('¡Cita agendada! Revisa tu correo y el PIN en Mis Citas.', 'success', 5000);
      this.onCitaCreada(data.cita);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Error al agendar. Intenta de nuevo.';
      errEl.textContent   = msg;
      errEl.style.display = 'flex';
      btnLoading(btn, false);
    }
  }
}

// ═══════════════════════════════════════════════════════════════════
// GESTIÓN DE ESTADOS DE CITA (Panel del negocio)
// ═══════════════════════════════════════════════════════════════════

/**
 * Confirma una cita (negocio → confirmada).
 * @param {number} citaId
 */
export async function confirmarCita(citaId) {
  return _cambiarEstado(citaId, 'confirmar');
}

/**
 * Inicia una cita validando el PIN del cliente (negocio → en_curso).
 * @param {number} citaId
 * @param {string} pin   — 4 dígitos introducidos por el negocio
 */
export async function iniciarCitaConPin(citaId, pin) {
  if (!/^\d{4}$/.test(pin)) {
    throw new Error('El PIN debe ser exactamente 4 dígitos numéricos.');
  }
  return _cambiarEstado(citaId, 'iniciar', { pin });
}

/**
 * Completa una cita (negocio → completada, libera anticipo).
 * @param {number} citaId
 */
export async function completarCita(citaId) {
  return _cambiarEstado(citaId, 'completar');
}

/**
 * Cancela una cita (cliente o negocio).
 * El servidor determina si aplica penalización según la ventana configurada.
 * @param {number} citaId
 */
export async function cancelarCita(citaId) {
  return _cambiarEstado(citaId, 'cancelar');
}

/** Abre el ticket reutilizable de Click & Collect. */
export function mostrarTicketRecoleccion(ticket) {
  document.getElementById('modal-ticket-recoleccion')?.remove();
  const modal = document.createElement('div');
  modal.id = 'modal-ticket-recoleccion';
  modal.className = 'modal-backdrop open';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-labelledby', 'ticket-titulo');

  const direccion = [ticket.direccion, ticket.ciudad].filter(Boolean).join(', ') || 'Consulta la dirección con el negocio';
  const total = ticket.monto_total ?? ticket.monto ?? 0;
  modal.innerHTML = `
    <div class="modal modal--ticket">
      <div class="modal__header">
        <h3 class="modal__title" id="ticket-titulo">Ticket de recolección</h3>
        <button class="modal__close" type="button" data-ticket-cerrar aria-label="Cerrar">✕</button>
      </div>
      <div class="modal__body">
        <article class="ticket-recoleccion">
          <div class="ticket-recoleccion__folio">Folio <strong>${_esc(ticket.folio ?? '')}</strong></div>
          <div class="ticket-recoleccion__sello">PAGADO - LISTO PARA RECOGER</div>
          <dl class="ticket-recoleccion__datos">
            <div><dt>Negocio</dt><dd>${_esc(ticket.negocio ?? '')}</dd></div>
            <div><dt>Dirección</dt><dd>${_esc(direccion)}</dd></div>
            <div><dt>Producto</dt><dd>${_esc(ticket.producto ?? '')}</dd></div>
            <div><dt>Cantidad</dt><dd>${_esc(ticket.cantidad ?? 1)}</dd></div>
            <div class="ticket-recoleccion__total"><dt>Total pagado</dt><dd>${formatMXN(total)}</dd></div>
          </dl>
          <p class="ticket-recoleccion__nota">Presenta este folio en el negocio para recoger tu compra.</p>
        </article>
      </div>
    </div>`;

  const cerrar = () => {
    document.removeEventListener('keydown', onKeydown);
    modal.remove();
  };
  const onKeydown = (event) => { if (event.key === 'Escape') cerrar(); };
  modal.addEventListener('click', (event) => {
    if (event.target === modal || event.target.closest('[data-ticket-cerrar]')) cerrar();
  });
  document.addEventListener('keydown', onKeydown);
  document.body.appendChild(modal);
  modal.querySelector('[data-ticket-cerrar]')?.focus();
}

async function _cambiarEstado(citaId, accion, extra = {}) {
  const data = await api.post('citas.php', { action: accion }, {
    cita_id: citaId,
    ...extra,
  });
  return data;
}

// ═══════════════════════════════════════════════════════════════════
// COUNTDOWN TIMER — Ventana PENDIENTE_PAGO (10 min)
// ═══════════════════════════════════════════════════════════════════

/**
 * Inicia un countdown visual para la ventana pendiente_pago.
 * @param {HTMLElement} elemento     — Donde se renderiza el contador
 * @param {string}      fechaLimite  — ISO string de fecha_limite_pago
 * @param {Function}    [onExpira]   — Callback cuando el timer llega a 0
 * @returns {Function} cancelar — Llama para limpiar el intervalo
 */
export function iniciarCountdown(elemento, fechaLimite, onExpira) {
  const limite = new Date(fechaLimite).getTime();

  const actualizar = () => {
    const ahora  = Date.now();
    const diff   = limite - ahora;

    if (diff <= 0) {
      elemento.textContent = '⏰ Tiempo expirado';
      elemento.classList.add('expired');
      clearInterval(timer);
      if (typeof onExpira === 'function') onExpira();
      return;
    }

    const mins = Math.floor(diff / 60_000);
    const segs = Math.floor((diff % 60_000) / 1000);
    elemento.textContent = `⏱ ${mins}:${String(segs).padStart(2, '0')} para confirmar`;
    elemento.classList.remove('expired');
  };

  actualizar();
  const timer = setInterval(actualizar, 1000);

  return () => clearInterval(timer);
}

// ─── Helpers privados ─────────────────────────────────────────────
function _isoDate(date) {
  return date.toISOString().split('T')[0];
}

function _formatHora(horaStr) {
  // horaStr: "09:30"
  const [h, m] = horaStr.split(':');
  const hora = parseInt(h, 10);
  const ampm = hora < 12 ? 'am' : 'pm';
  const h12  = hora === 0 ? 12 : hora > 12 ? hora - 12 : hora;
  return `${h12}:${m} ${ampm}`;
}

function _formatFechaHora(fechaISO, horaStr) {
  const [año, mes, dia] = fechaISO.split('-').map(Number);
  const [h, m]          = horaStr.split(':').map(Number);
  const fecha = new Date(año, mes - 1, dia, h, m);
  return fecha.toLocaleString('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function _esc(valor) {
  return String(valor ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
