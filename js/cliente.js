import { api, formatMXN, formatFecha, toast, btnLoading, badgeEstadoCita, badgeEstadoApartado } from './api.js';
import { mostrarTicketRecoleccion } from './agenda.js';

const estado = { citas: [], apartados: [], timers: [], metodosPago: [] };

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
  await Promise.all([cargarCitas(), cargarApartados(), cargarMetodosPago()]);
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
  document.getElementById('btn-tarjetas').addEventListener('click', abrirModalTarjetas);
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
    if (!estado.metodosPago.length) {
      abrirModalTarjetas();
      throw new Error('Agrega una tarjeta de prueba antes de realizar una recarga.');
    }
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

async function cargarMetodosPago() {
  const data = await api.get('pagos_simulador.php', { action: 'metodos_pago' });
  estado.metodosPago = data.metodos ?? [];
  return estado.metodosPago;
}

function abrirModalTarjetas() {
  document.getElementById('modal-metodos-pago')?.remove();
  const anioActual = new Date().getFullYear();
  const backdrop = document.createElement('div');
  backdrop.id = 'modal-metodos-pago';
  backdrop.className = 'modal-backdrop open';
  backdrop.setAttribute('role', 'dialog');
  backdrop.setAttribute('aria-modal', 'true');
  backdrop.setAttribute('aria-labelledby', 'titulo-tarjetas');
  backdrop.innerHTML = `
    <div class="modal modal--lg">
      <div class="modal__header">
        <div><h2 class="modal__title" id="titulo-tarjetas">Métodos de pago</h2><p class="text-xs text-muted">Simulación segura: nunca guardamos el número completo ni el CVV.</p></div>
        <button class="modal__close" type="button" data-cerrar-tarjetas aria-label="Cerrar">✕</button>
      </div>
      <div class="modal__body payment-layout">
        <section><h3 class="text-base">Tarjetas guardadas</h3><div id="lista-tarjetas" class="payment-method-list"></div></section>
        <form id="form-tarjeta" class="payment-card-form" novalidate autocomplete="off">
          <div class="payment-card-preview" aria-hidden="true"><span class="payment-card-preview__chip"></span><strong id="preview-numero">•••• •••• •••• ••••</strong><div><span id="preview-titular">NOMBRE DEL TITULAR</span><span id="preview-expira">MM/AA</span></div></div>
          <div class="form-group"><label class="form-label">Número de tarjeta de prueba</label><input class="form-control" name="numero" inputmode="numeric" maxlength="19" placeholder="4242 4242 4242 4242" required></div>
          <div class="form-group"><label class="form-label">Nombre del titular</label><input class="form-control" name="titular" maxlength="120" required></div>
          <div class="grid grid--3">
            <div class="form-group"><label class="form-label">Mes</label><input class="form-control" name="exp_mes" inputmode="numeric" min="1" max="12" placeholder="12" required></div>
            <div class="form-group"><label class="form-label">Año</label><input class="form-control" name="exp_anio" inputmode="numeric" min="${anioActual}" max="${anioActual + 20}" placeholder="${anioActual + 4}" required></div>
            <div class="form-group"><label class="form-label">CVV</label><input class="form-control" name="cvv" type="password" inputmode="numeric" maxlength="4" placeholder="123" required></div>
          </div>
          <div id="tarjeta-error" class="alert alert--danger" hidden></div>
          <button class="btn btn--primary btn--full" type="submit">Agregar tarjeta de prueba</button>
          <p class="text-xs text-muted">Puedes usar Visa 4242 4242 4242 4242, cualquier fecha futura y CVV 123.</p>
        </form>
      </div>
    </div>`;
  document.body.appendChild(backdrop);
  backdrop.addEventListener('click', (event) => { if (event.target === backdrop || event.target.closest('[data-cerrar-tarjetas]')) backdrop.remove(); });
  const form = backdrop.querySelector('#form-tarjeta');
  const numero = form.elements.numero;
  numero.addEventListener('input', () => { const limpio = numero.value.replace(/\D/g, '').slice(0, 16); numero.value = limpio.replace(/(.{4})/g, '$1 ').trim(); backdrop.querySelector('#preview-numero').textContent = numero.value || '•••• •••• •••• ••••'; });
  form.elements.titular.addEventListener('input', () => { backdrop.querySelector('#preview-titular').textContent = form.elements.titular.value.toUpperCase() || 'NOMBRE DEL TITULAR'; });
  const actualizarExpira = () => { backdrop.querySelector('#preview-expira').textContent = `${String(form.elements.exp_mes.value || 'MM').padStart(2, '0')}/${String(form.elements.exp_anio.value || 'AA').slice(-2)}`; };
  form.elements.exp_mes.addEventListener('input', actualizarExpira);
  form.elements.exp_anio.addEventListener('input', actualizarExpira);
  form.addEventListener('submit', guardarTarjeta);
  renderTarjetas(backdrop.querySelector('#lista-tarjetas'));
  numero.focus();
}

function renderTarjetas(contenedor) {
  contenedor.innerHTML = estado.metodosPago.length ? estado.metodosPago.map((metodo) => `<article class="payment-method"><div><strong>${esc(metodo.marca)} •••• ${esc(metodo.ultimos4)}</strong><p class="text-xs text-muted">${esc(metodo.titular)} · ${String(metodo.exp_mes).padStart(2, '0')}/${metodo.exp_anio}${Number(metodo.predeterminada) ? ' · Predeterminada' : ''}</p></div><button class="btn btn--ghost btn--sm" type="button" data-eliminar-tarjeta="${metodo.id}">Eliminar</button></article>`).join('') : '<div class="empty-state"><div class="empty-state__icon">💳</div><div class="empty-state__title">Sin tarjetas guardadas</div></div>';
  contenedor.querySelectorAll('[data-eliminar-tarjeta]').forEach((btn) => btn.addEventListener('click', async () => {
    if (!confirm('¿Eliminar esta tarjeta de prueba?')) return;
    await api.post('pagos_simulador.php', { action: 'eliminar_tarjeta' }, { id: Number(btn.dataset.eliminarTarjeta) });
    await cargarMetodosPago(); renderTarjetas(contenedor);
  }));
}

async function guardarTarjeta(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const error = form.querySelector('#tarjeta-error');
  const boton = form.querySelector('[type="submit"]');
  btnLoading(boton, true); error.hidden = true;
  try {
    await api.post('pagos_simulador.php', { action: 'agregar_tarjeta' }, Object.fromEntries(new FormData(form)));
    await cargarMetodosPago(); renderTarjetas(document.getElementById('lista-tarjetas'));
    form.reset();
    document.getElementById('preview-numero').textContent = '•••• •••• •••• ••••';
    document.getElementById('preview-titular').textContent = 'NOMBRE DEL TITULAR';
    document.getElementById('preview-expira').textContent = 'MM/AA';
    toast('Tarjeta de prueba agregada.', 'success');
  } catch (err) { error.textContent = err.message; error.hidden = false; }
  finally { btnLoading(boton, false, 'Agregar tarjeta de prueba'); }
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
      const reservada = ['agendada', 'confirmada'].includes(cita.estado);
      const prorrogaPendiente = cita.prorroga_estado === 'solicitada' && Number(cita.prorroga_solicitada_por) !== Number(cita.cliente_id);
      const acciones = [];
      if (reservada) {
        acciones.push(`<button class="btn btn--ghost btn--sm" data-cita-accion="generar_checkin" data-id="${cita.id}">Generar mi código de llegada</button>`);
        acciones.push(`<button class="btn btn--ghost btn--sm" data-cita-accion="validar_checkin" data-id="${cita.id}">Validar código del negocio</button>`);
        acciones.push(`<button class="btn btn--ghost btn--sm" data-cita-accion="solicitar_prorroga" data-id="${cita.id}">Pedir prórroga</button>`);
        acciones.push(`<button class="btn btn--danger btn--sm" data-cancelar-cita="${cita.id}">Cancelar cita</button>`);
      }
      if (prorrogaPendiente) acciones.push(`<button class="btn btn--primary btn--sm" data-cita-accion="responder_prorroga" data-id="${cita.id}">Responder prórroga</button>`);
      if (cita.estado === 'en_curso' && Number(cita.final_pendiente)) acciones.push(`<button class="btn btn--primary btn--sm" data-cita-accion="confirmar_final" data-id="${cita.id}">Confirmar servicio terminado</button>`);
      if (['en_curso', 'completada'].includes(cita.estado) || ['finalizacion_pendiente', 'retencion_24h'].includes(cita.estado_flujo)) acciones.push(`<button class="btn btn--ghost btn--sm" data-cita-accion="abrir_disputa" data-id="${cita.id}">Reportar un problema</button>`);
      return `<article class="cliente-item">
        <div class="cliente-item__header"><div><h3>${esc(cita.nombre_negocio)}</h3><p class="text-muted">${esc(cita.nombre_servicio)}</p></div>${badgeEstadoCita(cita.estado)}</div>
        <div class="cliente-item__meta"><span>📅 ${formatFecha(normalizarFecha(cita.fecha_hora_inicio), true)}</span><span>Garantía: ${formatMXN(cita.garantia ?? cita.monto_anticipo)}</span><span>Pagado: ${formatMXN(cita.monto_pagado ?? cita.monto_anticipo)}</span></div>
        ${cita.prorroga_estado === 'aceptada' ? `<div class="alert alert--success">Prórroga aceptada: ${Number(cita.prorroga_minutos)} minutos adicionales.</div>` : ''}
        ${acciones.length ? `<div class="cliente-item__actions">${acciones.join('')}</div>` : ''}
      </article>`;
    }).join('');
    lista.querySelectorAll('[data-cancelar-cita]').forEach((boton) => boton.addEventListener('click', () => confirmarCancelacion(Number(boton.dataset.cancelarCita))));
    lista.querySelectorAll('[data-cita-accion]').forEach((boton) => boton.addEventListener('click', () => manejarSeguridadCita(boton.dataset.citaAccion, Number(boton.dataset.id))));
  } catch (error) {
    lista.innerHTML = `<div class="alert alert--danger">${esc(error.message)}</div>`;
  }
}

async function confirmarCancelacion(citaId) {
  const cita = estado.citas.find((item) => Number(item.id) === citaId);
  if (!cita) return;
  const inicio = new Date(normalizarFecha(cita.fecha_hora_inicio)).getTime();
  const pagado = Number(cita.monto_pagado ?? cita.monto_anticipo);
  const detalle = Date.now() < inicio
    ? `La cancelación es gratuita hasta la hora exacta de inicio. Recibirás ${formatMXN(pagado)} de reembolso.`
    : 'La hora programada ya comenzó. Se registrará una ausencia; el negocio podrá perdonar o retener una parte de la garantía, pero nunca más que esa garantía.';
  if (!window.confirm(`¿Cancelar esta cita?\n\n${detalle}`)) return;

  try {
    const data = await api.post('citas.php', { action: 'cancelar' }, { cita_id: citaId });
    toast(data.mensaje, data.nuevo_estado === 'cancelada_tiempo' ? 'success' : 'warning', 6000);
    const me = await api.get('auth.php', { action: 'me' });
    renderSaldo(me.usuario.saldo_monedero);
    await cargarCitas();
  } catch (error) { toast(error.message, 'danger'); }
}

async function manejarSeguridadCita(accion, citaId) {
  try {
    if (accion === 'generar_checkin') {
      const data = await api.post('citas.php', { action: accion }, { cita_id: citaId });
      alert(`Tu código temporal de llegada es: ${data.codigo}\n\nMuéstralo al negocio. No lo compartas fuera del establecimiento.`);
    } else if (accion === 'validar_checkin' || accion === 'confirmar_final') {
      const etiqueta = accion === 'validar_checkin' ? 'código de llegada del negocio' : 'código final del servicio';
      const codigo = prompt(`Ingresa el ${etiqueta}:`);
      if (!codigo) return;
      const data = await api.post('citas.php', { action: accion }, { cita_id: citaId, codigo: codigo.trim() });
      toast(data.mensaje, 'success', 6000);
    } else if (accion === 'solicitar_prorroga') {
      const minutos = Number(prompt('Minutos de prórroga: 10, 15 o 30', '15'));
      if (![10, 15, 30].includes(minutos)) throw new Error('Selecciona una prórroga de 10, 15 o 30 minutos.');
      const data = await api.post('citas.php', { action: accion }, { cita_id: citaId, minutos });
      toast(data.mensaje, 'success');
    } else if (accion === 'responder_prorroga') {
      const aceptar = confirm('¿Aceptas la prórroga solicitada por el negocio?');
      const data = await api.post('citas.php', { action: accion }, { cita_id: citaId, aceptar });
      toast(data.mensaje, aceptar ? 'success' : 'warning');
    } else if (accion === 'abrir_disputa') {
      const descripcion = prompt('Describe el problema con al menos 10 caracteres. El pago permanecerá retenido durante la revisión:');
      if (!descripcion) return;
      const data = await api.post('citas.php', { action: accion }, { cita_id: citaId, motivo: 'servicio', descripcion });
      toast(data.mensaje, 'success', 7000);
    }
    await cargarCitas();
  } catch (error) { toast(error.message, 'danger', 6000); }
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
