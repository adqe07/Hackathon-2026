import { api, formatMXN, formatFecha, toast, btnLoading, badgeEstadoCita, badgeEstadoApartado } from './api.js';
import { mostrarTicketRecoleccion } from './agenda.js';

const estado = { citas: [], apartados: [], timers: [] };

async function iniciarPortalCliente() {
  try {
    const me = await api.get('auth.php', { action: 'me' });
    if (!me.autenticado || me.usuario.rol !== 'cliente') throw new Error('Se requiere una cuenta de cliente.');
    renderSaldo(me.usuario.saldo_monedero);
  } catch {
    window.location.href = 'login.html?redirect=mis-citas.html';
    return;
  }

  bindInterfaz();
  await Promise.all([cargarCitas(), cargarApartados()]);
}

function bindInterfaz() {
  document.querySelectorAll('[data-tab]').forEach((boton) => {
    boton.addEventListener('click', () => {
      const activo = boton.dataset.tab;
      document.querySelectorAll('[data-tab]').forEach((item) => {
        const seleccionado = item === boton;
        item.classList.toggle('active', seleccionado);
        item.setAttribute('aria-selected', String(seleccionado));
      });
      document.getElementById('panel-citas').hidden = activo !== 'citas';
      document.getElementById('panel-apartados').hidden = activo !== 'apartados';
    });
  });

  document.getElementById('btn-recargar').addEventListener('click', recargarMonedero);
  document.getElementById('btn-logout-cliente').addEventListener('click', async () => {
    try { await api.post('auth.php', { action: 'logout' }); } catch {}
    window.location.href = 'index.html';
  });
}

async function recargarMonedero(event) {
  const boton = event.currentTarget;
  const monto = Number(document.getElementById('monto-recarga').value);
  btnLoading(boton, true);
  try {
    await api.post('pagos_simulador.php', { action: 'recargar_monedero' }, { monto });
    const me = await api.get('auth.php', { action: 'me' });
    renderSaldo(me.usuario.saldo_monedero);
    toast(`Recarga de ${formatMXN(monto)} acreditada.`, 'success');
  } catch (error) {
    toast(error.message, 'danger');
  } finally {
    btnLoading(boton, false, 'Recarga rápida');
  }
}

async function cargarCitas() {
  const lista = document.getElementById('lista-citas');
  try {
    const data = await api.get('citas.php', { action: 'listar' });
    estado.citas = data.citas ?? [];
    if (!estado.citas.length) {
      lista.innerHTML = emptyState('🗓️', 'Aún no tienes citas', 'Explora negocios y agenda el servicio que necesitas.');
      return;
    }
    lista.innerHTML = estado.citas.map((cita) => {
      const cancelable = ['agendada', 'confirmada'].includes(cita.estado);
      const pin = cancelable && cita.pin_verificacion
        ? `<div class="cita-pin"><span>PIN de verificación</span><strong>${esc(cita.pin_verificacion)}</strong></div>` : '';
      return `<article class="cliente-item">
        <div class="cliente-item__header"><div><h3>${esc(cita.nombre_negocio)}</h3><p class="text-muted">${esc(cita.nombre_servicio)}</p></div>${badgeEstadoCita(cita.estado)}</div>
        <div class="cliente-item__meta"><span>📅 ${formatFecha(normalizarFecha(cita.fecha_hora_inicio), true)}</span><span>Anticipo: ${formatMXN(cita.monto_anticipo)}</span></div>
        ${pin}
        ${cancelable ? `<div class="cliente-item__actions"><button class="btn btn--danger btn--sm" data-cancelar-cita="${cita.id}">Cancelar Cita</button></div>` : ''}
      </article>`;
    }).join('');
    lista.querySelectorAll('[data-cancelar-cita]').forEach((boton) => boton.addEventListener('click', () => confirmarCancelacion(Number(boton.dataset.cancelarCita))));
  } catch (error) {
    lista.innerHTML = `<div class="alert alert--danger">${esc(error.message)}</div>`;
  }
}

async function confirmarCancelacion(citaId) {
  const cita = estado.citas.find((item) => Number(item.id) === citaId);
  if (!cita) return;
  const inicio = new Date(normalizarFecha(cita.fecha_hora_inicio)).getTime();
  const horasRestantes = (inicio - Date.now()) / 3600000;
  const ventana = Number(cita.ventana_cancelacion_horas);
  const reembolsa = horasRestantes >= ventana;
  const detalle = reembolsa
    ? `Recibirás el reembolso total de ${formatMXN(cita.monto_anticipo)} en tu monedero.`
    : `Perderás el anticipo de ${formatMXN(cita.monto_anticipo)} porque faltan menos de ${ventana} horas.`;
  if (!window.confirm(`¿Cancelar esta cita?\n\n${detalle}`)) return;

  try {
    const data = await api.post('citas.php', { action: 'cancelar' }, { cita_id: citaId });
    toast(data.mensaje, data.nuevo_estado === 'cancelada_tiempo' ? 'success' : 'warning', 6000);
    const me = await api.get('auth.php', { action: 'me' });
    renderSaldo(me.usuario.saldo_monedero);
    await cargarCitas();
  } catch (error) { toast(error.message, 'danger'); }
}

async function cargarApartados() {
  limpiarTimers();
  const lista = document.getElementById('lista-apartados');
  try {
    const data = await api.get('productos.php', { action: 'mis_apartados' });
    estado.apartados = data.apartados ?? [];
    if (!estado.apartados.length) {
      lista.innerHTML = emptyState('🛍️', 'Aún no tienes apartados', 'Aparta un producto y recógelo directamente en el negocio.');
      return;
    }
    lista.innerHTML = estado.apartados.map((apartado) => {
      const ttl = apartado.estado === 'pendiente' && apartado.tipo_pago === 'efectivo_tienda'
        ? `<span class="countdown-apartado" data-expira="${esc(normalizarFecha(apartado.fecha_expiracion))}"></span>` : '';
      const ticket = apartado.estado === 'pagado'
        ? `<button class="btn btn--primary btn--sm" data-ticket="${apartado.id}">Ver Ticket de Recolección</button>` : '';
      const pago = apartado.estado === 'pendiente' && apartado.tipo_pago === 'online'
        ? `<button class="btn btn--primary btn--sm" data-pagar="${apartado.id}">Completar pago</button>` : '';
      return `<article class="cliente-item">
        <div class="cliente-item__header"><div><h3>${esc(apartado.titulo_producto)}</h3><p class="text-muted">${esc(apartado.nombre_negocio)}</p></div>${badgeEstadoApartado(apartado.estado)}</div>
        <div class="cliente-item__meta"><span>Cantidad: ${esc(apartado.cantidad)}</span><span>Total: ${formatMXN(apartado.monto_total)}</span><span>${apartado.tipo_pago === 'online' ? 'Pago en línea' : 'Efectivo en tienda'}</span>${ttl}</div>
        ${(ticket || pago) ? `<div class="cliente-item__actions">${ticket}${pago}</div>` : ''}
      </article>`;
    }).join('');
    iniciarContadores();
    lista.querySelectorAll('[data-ticket]').forEach((boton) => boton.addEventListener('click', () => verTicket(Number(boton.dataset.ticket))));
    lista.querySelectorAll('[data-pagar]').forEach((boton) => boton.addEventListener('click', () => pagarApartado(Number(boton.dataset.pagar), boton)));
  } catch (error) { lista.innerHTML = `<div class="alert alert--danger">${esc(error.message)}</div>`; }
}

async function pagarApartado(id, boton) {
  btnLoading(boton, true);
  try {
    const data = await api.post('pagos_simulador.php', { action: 'pagar_apartado' }, { apartado_id: id });
    mostrarTicketRecoleccion(data.ticket);
    await cargarApartados();
  } catch (error) { toast(error.message, 'danger'); btnLoading(boton, false); }
}

async function verTicket(id) {
  try {
    const data = await api.get('productos.php', { action: 'ticket', apartado_id: id });
    mostrarTicketRecoleccion(data.ticket);
  } catch (error) { toast(error.message, 'danger'); }
}

function iniciarContadores() {
  document.querySelectorAll('[data-expira]').forEach((elemento) => {
    const actualizar = () => {
      const diff = new Date(elemento.dataset.expira).getTime() - Date.now();
      if (diff <= 0) { elemento.textContent = 'Apartado expirado'; elemento.classList.add('expired'); return false; }
      const horas = Math.floor(diff / 3600000);
      const minutos = Math.floor((diff % 3600000) / 60000);
      const segundos = Math.floor((diff % 60000) / 1000);
      elemento.textContent = `Expira en ${horas}h ${String(minutos).padStart(2, '0')}m ${String(segundos).padStart(2, '0')}s`;
      return true;
    };
    actualizar();
    const timer = setInterval(() => { if (!actualizar()) clearInterval(timer); }, 1000);
    estado.timers.push(timer);
  });
}

function limpiarTimers() { estado.timers.forEach(clearInterval); estado.timers = []; }
function renderSaldo(saldo) { document.getElementById('saldo-monedero').textContent = formatMXN(saldo); }
function normalizarFecha(fecha) { return String(fecha ?? '').replace(' ', 'T'); }
function emptyState(icono, titulo, texto) { return `<div class="empty-state"><div class="empty-state__icon">${icono}</div><div class="empty-state__title">${titulo}</div><p class="empty-state__desc">${texto}</p></div>`; }
function esc(valor) { return String(valor ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }

iniciarPortalCliente();
