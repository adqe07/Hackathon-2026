/**
 * js/panel.js
 * -------------------------------------------------
 * CRUD de productos y servicios, gestión de
 * apartados y citas para el panel del negocio.
 * -------------------------------------------------
 */

import { api, ApiError, formatMXN, formatFecha, toast, btnLoading, badgeEstadoCita, badgeEstadoApartado } from './api.js';
import { iniciarCitaConPin, confirmarCita, completarCita, cancelarCita } from './agenda.js';

// ─── Estado del panel ─────────────────────────────────────────────
const estado = {
  negocioId:  null,
  seccion:    'dashboard',
  productos:  [],
  servicios:  [],
  citas:      [],
  apartados:  [],
};

// ═══════════════════════════════════════════════════════════════════
// INICIALIZACIÓN
// ═══════════════════════════════════════════════════════════════════
export async function initPanel() {
  if (!document.getElementById('panel-layout')) return;

  // Verificar sesión y rol
  try {
    const me = await api.get('auth.php', { action: 'me' });
    if (!me.autenticado || me.usuario.rol !== 'negocio') {
      window.location.href = '/hackathon/login.html?redirect=panel-negocio.html';
      return;
    }
    estado.negocioId = me.negocio?.id ?? null;
    _renderHeaderUsuario(me.usuario, me.negocio);
  } catch {
    window.location.href = '/hackathon/login.html?redirect=panel-negocio.html';
    return;
  }

  if (!estado.negocioId) {
    _mostrarFormCrearNegocio();
    return;
  }

  _bindNavPanel();
  _activarSeccion('dashboard');
}

// ─── Navegación del panel ─────────────────────────────────────────
function _bindNavPanel() {
  document.querySelectorAll('.panel-nav__item[data-seccion]').forEach(item => {
    item.addEventListener('click', () => {
      const seccion = item.dataset.seccion;
      _activarSeccion(seccion);
    });
  });

  // Logout
  const manejarLogout = async () => {
    try {
      await api.post('auth.php', { action: 'logout' });
    } catch {}
    window.location.href = 'index.html';
  };

  document.getElementById('btn-logout')?.addEventListener('click', manejarLogout);
  document.getElementById('btn-logout-mobile')?.addEventListener('click', manejarLogout);
}

async function _activarSeccion(nombre) {
  estado.seccion = nombre;

  document.querySelectorAll('.panel-nav__item').forEach(el => {
    el.classList.toggle('active', el.dataset.seccion === nombre);
  });

  document.querySelectorAll('.panel-seccion').forEach(el => {
    el.style.display = el.id === `sec-${nombre}` ? 'block' : 'none';
  });

  switch (nombre) {
    case 'dashboard':  await cargarDashboard();   break;
    case 'productos':  await cargarProductos();   break;
    case 'servicios':  await cargarServicios();   break;
    case 'citas':      await cargarCitas();        break;
    case 'apartados':  await cargarApartados();    break;
  }
}

// ═══════════════════════════════════════════════════════════════════
// DASHBOARD — KPIs
// ═══════════════════════════════════════════════════════════════════
async function cargarDashboard() {
  const el = document.getElementById('sec-dashboard');
  if (!el) return;

  el.innerHTML = `<div style="text-align:center;padding:var(--space-8);"><span class="spinner"></span></div>`;

  try {
    const data = await api.get('negocios.php', {
      action:     'dashboard',
      negocio_id: estado.negocioId,
    });

    const kpis = data.kpis ?? {};
    el.innerHTML = `
      <h2 class="section-title">Dashboard</h2>
      <p class="section-subtitle">Resumen de tu negocio hoy.</p>
      <div class="grid grid--4" style="margin-bottom:var(--space-8);">
        <div class="stat-card">
          <div class="stat-card__icon stat-card__icon--primary">📦</div>
          <div>
            <div class="stat-card__value">${kpis.total_productos ?? 0}</div>
            <div class="stat-card__label">Productos activos</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-card__icon stat-card__icon--success">✅</div>
          <div>
            <div class="stat-card__value">${kpis.citas_hoy ?? 0}</div>
            <div class="stat-card__label">Citas hoy</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-card__icon stat-card__icon--warning">🛒</div>
          <div>
            <div class="stat-card__value">${kpis.apartados_pendientes ?? 0}</div>
            <div class="stat-card__label">Apartados pendientes</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-card__icon stat-card__icon--success">💰</div>
          <div>
            <div class="stat-card__value">${formatMXN(kpis.ingresos_mes ?? 0)}</div>
            <div class="stat-card__label">Ingresos este mes</div>
          </div>
        </div>
      </div>
      <div class="monedero-banner">
        <div>
          <div class="monedero-banner__label">Saldo en custodia (anticipos)</div>
          <div class="monedero-banner__monto">${formatMXN(kpis.saldo_escrow ?? 0)}</div>
          <div class="monedero-banner__sub">Se libera al completar las citas agendadas</div>
        </div>
        <span style="font-size:3rem;">💳</span>
      </div>`;
  } catch (err) {
    el.innerHTML = `<div class="alert alert--danger">Error al cargar el dashboard. ${err.message}</div>`;
  }
}

// ═══════════════════════════════════════════════════════════════════
// PRODUCTOS — CRUD
// ═══════════════════════════════════════════════════════════════════
async function cargarProductos() {
  const el = document.getElementById('sec-productos');
  if (!el) return;

  el.innerHTML = `
    <div class="flex items-center justify-between mb-6" style="flex-wrap:wrap;gap:var(--space-3);">
      <div>
        <h2 class="section-title">Productos</h2>
        <p class="section-subtitle" style="margin-bottom:0;">Gestiona tu inventario.</p>
      </div>
      <button id="btn-nuevo-producto" class="btn btn--primary">+ Nuevo producto</button>
    </div>
    <div id="productos-lista"><span class="spinner"></span></div>`;

  document.getElementById('btn-nuevo-producto').addEventListener('click', () => {
    _abrirModalProducto(null);
  });

  await _fetchProductos();
}

async function _fetchProductos() {
  try {
    const data = await api.get('productos.php', {
      action:     'listar',
      negocio_id: estado.negocioId,
    });
    estado.productos = data.productos ?? [];
    _renderProductos();
  } catch (err) {
    document.getElementById('productos-lista').innerHTML =
      `<div class="alert alert--danger">${err.message}</div>`;
  }
}

function _renderProductos() {
  const el = document.getElementById('productos-lista');
  if (!estado.productos.length) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-state__icon">📦</div>
        <div class="empty-state__title">Sin productos</div>
        <p class="empty-state__desc">Agrega tu primer producto para que los clientes puedan verlo.</p>
      </div>`;
    return;
  }

  el.innerHTML = `
    <div class="grid grid--auto">
      ${estado.productos.map(p => `
        <div class="producto-card" data-id="${p.id}">
          <div class="producto-card__img">
            ${p.imagen_url
              ? `<img src="${_escape(p.imagen_url)}" alt="${_escape(p.titulo)}" loading="lazy">`
              : `<div class="producto-card__img-placeholder">📦</div>`}
            <div class="producto-card__stock-badge${p.stock_disponible === 0 ? ' agotado' : ''}">
              ${p.stock_disponible === 0 ? '¡Agotado!' : `${p.stock_disponible} en stock`}
            </div>
          </div>
          <div class="producto-card__body">
            <div class="producto-card__titulo">${_escape(p.titulo)}</div>
            <div class="producto-card__precio">
              <span class="moneda">$</span>${parseFloat(p.precio).toFixed(2)}
            </div>
            <div class="flex gap-2" style="margin-top:auto;">
              <button class="btn btn--ghost btn--sm btn-editar-producto" data-id="${p.id}">✏️ Editar</button>
              <button class="btn btn--danger btn--sm btn-eliminar-producto" data-id="${p.id}">🗑</button>
            </div>
          </div>
        </div>`).join('')}
    </div>`;

  el.querySelectorAll('.btn-editar-producto').forEach(btn => {
    btn.addEventListener('click', () => {
      const prod = estado.productos.find(p => p.id == btn.dataset.id);
      if (prod) _abrirModalProducto(prod);
    });
  });

  el.querySelectorAll('.btn-eliminar-producto').forEach(btn => {
    btn.addEventListener('click', () => _confirmarEliminarProducto(parseInt(btn.dataset.id)));
  });
}

function _abrirModalProducto(producto) {
  const esEdicion = !!producto;
  const modal = _crearModal(
    esEdicion ? 'Editar producto' : 'Nuevo producto',
    `<form id="form-producto" novalidate>
      <div class="form-group">
        <label class="form-label">Título <span class="required">*</span></label>
        <input type="text" name="titulo" class="form-control" required maxlength="200"
               value="${_escape(producto?.titulo ?? '')}">
      </div>
      <div class="form-group">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" class="form-control" rows="3">${_escape(producto?.descripcion ?? '')}</textarea>
      </div>
      <div class="grid grid--2">
        <div class="form-group">
          <label class="form-label">Precio (MXN) <span class="required">*</span></label>
          <input type="number" name="precio" class="form-control" step="0.01" min="0" required
                 value="${producto?.precio ?? ''}">
        </div>
        <div class="form-group">
          <label class="form-label">Stock total <span class="required">*</span></label>
          <input type="number" name="stock_total" class="form-control" min="0" required
                 value="${producto?.stock_total ?? '0'}">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">URL de imagen</label>
        <input type="url" name="imagen_url" class="form-control" placeholder="https://…"
               value="${_escape(producto?.imagen_url ?? '')}">
        <span class="form-hint">Pega la URL pública de la imagen del producto.</span>
      </div>
      <div id="form-prod-error" class="alert alert--danger" style="display:none;"></div>
    </form>`,
    [
      { texto: 'Cancelar', clase: 'btn--ghost', accion: 'cerrar' },
      { texto: esEdicion ? 'Guardar cambios' : 'Crear producto', clase: 'btn--primary', accion: 'submit', id: 'btn-guardar-prod' },
    ]
  );

  modal.querySelector('[data-accion="submit"]').addEventListener('click', async () => {
    const form    = modal.querySelector('#form-producto');
    const errEl   = modal.querySelector('#form-prod-error');
    const btn     = modal.querySelector('#btn-guardar-prod');
    const payload = Object.fromEntries(new FormData(form));

    if (!payload.titulo?.trim() || !payload.precio || !payload.stock_total) {
      errEl.textContent   = 'Título, precio y stock son obligatorios.';
      errEl.style.display = 'flex';
      return;
    }

    errEl.style.display = 'none';
    btnLoading(btn, true);

    try {
      if (esEdicion) {
        await api.post('productos.php', { action: 'editar' }, {
          id: producto.id, ...payload,
        });
        toast('Producto actualizado.', 'success');
      } else {
        await api.post('productos.php', { action: 'crear' }, {
          negocio_id: estado.negocioId, ...payload,
        });
        toast('Producto creado.', 'success');
      }
      _cerrarModal(modal);
      await _fetchProductos();
    } catch (err) {
      errEl.textContent   = err.message;
      errEl.style.display = 'flex';
      btnLoading(btn, false);
    }
  });
}

async function _confirmarEliminarProducto(id) {
  if (!confirm('¿Eliminar este producto? Esta acción no se puede deshacer.')) return;
  try {
    await api.post('productos.php', { action: 'eliminar' }, { id });
    toast('Producto eliminado.', 'success');
    await _fetchProductos();
  } catch (err) {
    toast(err.message, 'danger');
  }
}

// ═══════════════════════════════════════════════════════════════════
// SERVICIOS — CRUD
// ═══════════════════════════════════════════════════════════════════
async function cargarServicios() {
  const el = document.getElementById('sec-servicios');
  if (!el) return;

  el.innerHTML = `
    <div class="flex items-center justify-between mb-6" style="flex-wrap:wrap;gap:var(--space-3);">
      <div>
        <h2 class="section-title">Servicios</h2>
        <p class="section-subtitle" style="margin-bottom:0;">Define los servicios que ofreces.</p>
      </div>
      <button id="btn-nuevo-servicio" class="btn btn--primary">+ Nuevo servicio</button>
    </div>
    <div id="servicios-lista"><span class="spinner"></span></div>`;

  document.getElementById('btn-nuevo-servicio').addEventListener('click', () => {
    _abrirModalServicio(null);
  });

  await _fetchServicios();
}

async function _fetchServicios() {
  try {
    const data = await api.get('productos.php', {
      action:     'servicios',
      negocio_id: estado.negocioId,
    });
    estado.servicios = data.servicios ?? [];
    _renderServicios();
  } catch (err) {
    document.getElementById('servicios-lista').innerHTML =
      `<div class="alert alert--danger">${err.message}</div>`;
  }
}

function _renderServicios() {
  const el = document.getElementById('servicios-lista');
  if (!estado.servicios.length) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-state__icon">🗓</div>
        <div class="empty-state__title">Sin servicios</div>
        <p class="empty-state__desc">Agrega servicios para que los clientes puedan agendar citas.</p>
      </div>`;
    return;
  }

  el.innerHTML = `<div style="display:flex;flex-direction:column;gap:var(--space-3);">
    ${estado.servicios.map(s => `
      <div class="servicio-card">
        <div class="servicio-card__header">
          <div class="servicio-card__nombre">${_escape(s.nombre)}</div>
          <div class="servicio-card__duracion">⏱ ${s.duracion_minutos} min</div>
        </div>
        ${s.descripcion ? `<p class="servicio-card__desc">${_escape(s.descripcion)}</p>` : ''}
        <div class="servicio-card__footer">
          <div class="servicio-card__precios">
            <div class="servicio-card__precio-estimado">${formatMXN(s.precio_estimado)}</div>
            <div class="servicio-card__anticipo">Anticipo: <strong>${formatMXN(s.monto_anticipo)}</strong></div>
          </div>
          <div class="flex gap-2">
            <button class="btn btn--ghost btn--sm btn-editar-servicio" data-id="${s.id}">✏️ Editar</button>
            <button class="btn btn--danger btn--sm btn-eliminar-servicio" data-id="${s.id}">🗑</button>
          </div>
        </div>
      </div>`).join('')}
  </div>`;

  el.querySelectorAll('.btn-editar-servicio').forEach(btn => {
    btn.addEventListener('click', () => {
      const sv = estado.servicios.find(s => s.id == btn.dataset.id);
      if (sv) _abrirModalServicio(sv);
    });
  });

  el.querySelectorAll('.btn-eliminar-servicio').forEach(btn => {
    btn.addEventListener('click', () => _confirmarEliminarServicio(parseInt(btn.dataset.id)));
  });
}

function _abrirModalServicio(servicio) {
  const esEdicion = !!servicio;
  const modal = _crearModal(
    esEdicion ? 'Editar servicio' : 'Nuevo servicio',
    `<form id="form-servicio" novalidate>
      <div class="form-group">
        <label class="form-label">Nombre <span class="required">*</span></label>
        <input type="text" name="nombre" class="form-control" required maxlength="150"
               value="${_escape(servicio?.nombre ?? '')}">
      </div>
      <div class="form-group">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" class="form-control" rows="3">${_escape(servicio?.descripcion ?? '')}</textarea>
      </div>
      <div class="grid grid--2">
        <div class="form-group">
          <label class="form-label">Duración (minutos) <span class="required">*</span></label>
          <input type="number" name="duracion_minutos" class="form-control" min="15" step="15" required
                 value="${servicio?.duracion_minutos ?? '60'}">
        </div>
        <div class="form-group">
          <label class="form-label">Precio estimado (MXN) <span class="required">*</span></label>
          <input type="number" name="precio_estimado" class="form-control" step="0.01" min="0" required
                 value="${servicio?.precio_estimado ?? ''}">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Monto de anticipo (MXN) <span class="required">*</span></label>
        <input type="number" name="monto_anticipo" class="form-control" step="0.01" min="0" required
               value="${servicio?.monto_anticipo ?? ''}">
        <span class="form-hint">Monto que el cliente paga al agendar. Se retiene hasta completar la cita.</span>
      </div>
      <div id="form-svc-error" class="alert alert--danger" style="display:none;"></div>
    </form>`,
    [
      { texto: 'Cancelar', clase: 'btn--ghost', accion: 'cerrar' },
      { texto: esEdicion ? 'Guardar' : 'Crear servicio', clase: 'btn--primary', accion: 'submit', id: 'btn-guardar-svc' },
    ]
  );

  modal.querySelector('[data-accion="submit"]').addEventListener('click', async () => {
    const form    = modal.querySelector('#form-servicio');
    const errEl   = modal.querySelector('#form-svc-error');
    const btn     = modal.querySelector('#btn-guardar-svc');
    const payload = Object.fromEntries(new FormData(form));

    if (!payload.nombre?.trim() || !payload.precio_estimado || !payload.duracion_minutos) {
      errEl.textContent   = 'Nombre, duración y precio son obligatorios.';
      errEl.style.display = 'flex';
      return;
    }

    errEl.style.display = 'none';
    btnLoading(btn, true);

    try {
      if (esEdicion) {
        await api.post('productos.php', { action: 'editar_servicio' }, { id: servicio.id, ...payload });
        toast('Servicio actualizado.', 'success');
      } else {
        await api.post('productos.php', { action: 'crear_servicio' }, { negocio_id: estado.negocioId, ...payload });
        toast('Servicio creado.', 'success');
      }
      _cerrarModal(modal);
      await _fetchServicios();
    } catch (err) {
      errEl.textContent   = err.message;
      errEl.style.display = 'flex';
      btnLoading(btn, false);
    }
  });
}

async function _confirmarEliminarServicio(id) {
  if (!confirm('¿Eliminar este servicio?')) return;
  try {
    await api.post('productos.php', { action: 'eliminar_servicio' }, { id });
    toast('Servicio eliminado.', 'success');
    await _fetchServicios();
  } catch (err) {
    toast(err.message, 'danger');
  }
}

// ═══════════════════════════════════════════════════════════════════
// CITAS — Gestión de agenda
// ═══════════════════════════════════════════════════════════════════
async function cargarCitas() {
  const el = document.getElementById('sec-citas');
  if (!el) return;

  el.innerHTML = `
    <h2 class="section-title">Agenda de Citas</h2>
    <div class="flex gap-2 mb-6" style="flex-wrap:wrap;">
      ${['todas','agendada','confirmada','en_curso','completada'].map(e =>
        `<button class="chip filtro-cita${e==='todas'?' active':''}" data-estado="${e}">
          ${e === 'todas' ? '📋 Todas' : e.charAt(0).toUpperCase() + e.slice(1).replace('_',' ')}
        </button>`).join('')}
    </div>
    <div id="citas-lista"><span class="spinner"></span></div>`;

  el.querySelectorAll('.filtro-cita').forEach(chip => {
    chip.addEventListener('click', async () => {
      el.querySelectorAll('.filtro-cita').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      await _fetchCitas(chip.dataset.estado === 'todas' ? '' : chip.dataset.estado);
    });
  });

  await _fetchCitas('');
}

async function _fetchCitas(filtroEstado = '') {
  const listaEl = document.getElementById('citas-lista');
  listaEl.innerHTML = `<span class="spinner"></span>`;
  try {
    const data = await api.get('citas.php', {
      action:     'listar',
      negocio_id: estado.negocioId,
      estado:     filtroEstado || undefined,
    });
    estado.citas = data.citas ?? [];
    _renderCitas(listaEl);
  } catch (err) {
    listaEl.innerHTML = `<div class="alert alert--danger">${err.message}</div>`;
  }
}

function _renderCitas(el) {
  if (!estado.citas.length) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-state__icon">🗓</div>
        <div class="empty-state__title">Sin citas</div>
        <p class="empty-state__desc">No hay citas con este filtro.</p>
      </div>`;
    return;
  }

  el.innerHTML = `<div style="display:flex;flex-direction:column;gap:var(--space-3);">
    ${estado.citas.map(c => `
      <div class="cita-card" data-cita-id="${c.id}">
        <div class="cita-card__header">
          <div>
            <div class="cita-card__servicio">${_escape(c.nombre_servicio ?? 'Servicio')}</div>
            <div class="cita-card__cliente">👤 ${_escape(c.nombre_cliente ?? 'Cliente')}</div>
          </div>
          ${badgeEstadoCita(c.estado)}
        </div>
        <div class="cita-card__fecha">
          📅 ${formatFecha(c.fecha_hora_inicio, true)}
          <span class="text-muted text-xs">(${c.duracion_minutos ?? '?'} min)</span>
        </div>
        ${c.estado === 'agendada' || c.estado === 'confirmada' ? `
          <div class="cita-card__pin">
            🔑 PIN del cliente: <span>${c.pin_verificacion ?? '????'}</span>
          </div>` : ''}
        <div class="cita-card__acciones">
          ${_botonesAccionCita(c)}
        </div>
      </div>`).join('')}
  </div>`;

  // Bind acciones
  el.querySelectorAll('[data-accion-cita]').forEach(btn => {
    btn.addEventListener('click', () => _manejarAccionCita(btn));
  });
}

function _botonesAccionCita(cita) {
  const btns = [];
  if (cita.estado === 'agendada') {
    btns.push(`<button class="btn btn--primary btn--sm" data-accion-cita="confirmar" data-id="${cita.id}">✅ Confirmar</button>`);
    btns.push(`<button class="btn btn--danger btn--sm" data-accion-cita="cancelar" data-id="${cita.id}">✖ Cancelar</button>`);
  }
  if (cita.estado === 'confirmada') {
    btns.push(`<button class="btn btn--success btn--sm" data-accion-cita="iniciar" data-id="${cita.id}">▶️ Iniciar con PIN</button>`);
    btns.push(`<button class="btn btn--danger btn--sm" data-accion-cita="cancelar" data-id="${cita.id}">✖ Cancelar</button>`);
  }
  if (cita.estado === 'en_curso') {
    btns.push(`<button class="btn btn--success btn--sm" data-accion-cita="completar" data-id="${cita.id}">🏁 Completar</button>`);
  }
  return btns.join('');
}

async function _manejarAccionCita(btn) {
  const accion = btn.dataset.accionCita;
  const citaId = parseInt(btn.dataset.id);

  btnLoading(btn, true);
  try {
    if (accion === 'confirmar') {
      await confirmarCita(citaId);
      toast('Cita confirmada.', 'success');
    } else if (accion === 'completar') {
      await completarCita(citaId);
      toast('Cita completada. Anticipo liberado a tu saldo.', 'success');
    } else if (accion === 'cancelar') {
      if (!confirm('¿Cancelar esta cita?')) { btnLoading(btn, false); return; }
      await cancelarCita(citaId);
      toast('Cita cancelada.', 'success');
    } else if (accion === 'iniciar') {
      const pin = prompt('Ingresa el PIN de 4 dígitos que muestra el cliente:');
      if (!pin) { btnLoading(btn, false); return; }
      await iniciarCitaConPin(citaId, pin);
      toast('¡Cita en curso!', 'success');
    }
    await _fetchCitas('');
  } catch (err) {
    toast(err.message, 'danger');
    btnLoading(btn, false);
  }
}

// ═══════════════════════════════════════════════════════════════════
// APARTADOS
// ═══════════════════════════════════════════════════════════════════
async function cargarApartados() {
  const el = document.getElementById('sec-apartados');
  if (!el) return;

  el.innerHTML = `
    <h2 class="section-title">Apartados</h2>
    <div id="apartados-lista"><span class="spinner"></span></div>`;

  try {
    const data = await api.get('productos.php', {
      action:     'apartados',
      negocio_id: estado.negocioId,
    });
    estado.apartados = data.apartados ?? [];
    _renderApartados(document.getElementById('apartados-lista'));
  } catch (err) {
    document.getElementById('apartados-lista').innerHTML =
      `<div class="alert alert--danger">${err.message}</div>`;
  }
}

function _renderApartados(el) {
  if (!estado.apartados.length) {
    el.innerHTML = `
      <div class="empty-state">
        <div class="empty-state__icon">🛍️</div>
        <div class="empty-state__title">Sin apartados</div>
        <p class="empty-state__desc">No hay apartados activos en tu negocio.</p>
      </div>`;
    return;
  }

  el.innerHTML = `
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:var(--text-sm);">
        <thead>
          <tr style="border-bottom:2px solid var(--color-border);">
            <th style="padding:var(--space-3);text-align:left;">Producto</th>
            <th style="padding:var(--space-3);text-align:left;">Cliente</th>
            <th style="padding:var(--space-3);text-align:center;">Cant.</th>
            <th style="padding:var(--space-3);text-align:right;">Monto</th>
            <th style="padding:var(--space-3);text-align:center;">Tipo pago</th>
            <th style="padding:var(--space-3);text-align:center;">Estado</th>
            <th style="padding:var(--space-3);text-align:left;">Expira</th>
            <th style="padding:var(--space-3);text-align:center;">Acción</th>
          </tr>
        </thead>
        <tbody>
          ${estado.apartados.map(a => `
            <tr style="border-bottom:1px solid var(--color-border);">
              <td style="padding:var(--space-3);">${_escape(a.titulo_producto ?? '—')}</td>
              <td style="padding:var(--space-3);">${_escape(a.nombre_cliente ?? '—')}</td>
              <td style="padding:var(--space-3);text-align:center;">${a.cantidad}</td>
              <td style="padding:var(--space-3);text-align:right;font-weight:var(--weight-semibold);">${formatMXN(a.monto_total)}</td>
              <td style="padding:var(--space-3);text-align:center;">${a.tipo_pago === 'efectivo_tienda' ? '💵 Efectivo' : '💳 Online'}</td>
              <td style="padding:var(--space-3);text-align:center;">${badgeEstadoApartado(a.estado)}</td>
              <td style="padding:var(--space-3);font-size:var(--text-xs);color:var(--color-text-muted);">${formatFecha(a.fecha_expiracion, true)}</td>
              <td style="padding:var(--space-3);text-align:center;">
                ${a.estado === 'pendiente' && a.tipo_pago === 'efectivo_tienda'
                  ? `<button class="btn btn--success btn--sm" data-accion-apartado="marcar_pagado" data-id="${a.id}">✅ Marcar pagado</button>`
                  : '—'}
              </td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;

  el.querySelectorAll('[data-accion-apartado]').forEach(btn => {
    btn.addEventListener('click', async () => {
      btnLoading(btn, true);
      try {
        await api.post('productos.php', { action: 'marcar_pagado' }, { id: parseInt(btn.dataset.id) });
        toast('Apartado marcado como pagado.', 'success');
        await cargarApartados();
      } catch (err) {
        toast(err.message, 'danger');
        btnLoading(btn, false);
      }
    });
  });
}

// ═══════════════════════════════════════════════════════════════════
// HELPERS DE MODAL
// ═══════════════════════════════════════════════════════════════════
function _crearModal(titulo, bodyHTML, botones = []) {
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true" aria-label="${_escape(titulo)}">
      <div class="modal__handle"></div>
      <div class="modal__header">
        <h3 class="modal__title">${_escape(titulo)}</h3>
        <button class="modal__close" data-accion="cerrar" aria-label="Cerrar">✕</button>
      </div>
      <div class="modal__body">${bodyHTML}</div>
      <div class="modal__footer">
        ${botones.map(b => `
          <button class="btn ${b.clase}" data-accion="${b.accion}" ${b.id ? `id="${b.id}"` : ''}>
            ${_escape(b.texto)}
          </button>`).join('')}
      </div>
    </div>`;

  document.body.appendChild(backdrop);
  requestAnimationFrame(() => backdrop.classList.add('open'));

  backdrop.querySelectorAll('[data-accion="cerrar"]').forEach(el => {
    el.addEventListener('click', () => _cerrarModal(backdrop));
  });

  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) _cerrarModal(backdrop);
  });

  return backdrop;
}

function _cerrarModal(backdrop) {
  backdrop.classList.remove('open');
  setTimeout(() => backdrop.remove(), 300);
}

function _renderHeaderUsuario(usuario, negocio) {
  const el = document.getElementById('panel-usuario-info');
  if (!el) return;
  el.innerHTML = `
    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);">${_escape(usuario.nombre)}</div>
    ${negocio ? `<div style="font-size:var(--text-xs);color:var(--color-text-muted);">${_escape(negocio.nombre)}</div>` : ''}`;
}

function _mostrarFormCrearNegocio() {
  const main = document.getElementById('panel-main') ?? document.body;
  main.innerHTML = `
    <div class="container container--sm" style="padding-top:var(--space-10);">
      <h2 class="section-title text-center">Configura tu negocio</h2>
      <p class="section-subtitle text-center">Antes de continuar, necesitamos los datos básicos de tu negocio.</p>
      <div class="alert alert--info">ℹ️ Solo necesitas hacer esto una vez.</div>
      <a href="/hackathon/index.html" class="btn btn--ghost btn--full">Ir al inicio por ahora</a>
    </div>`;
}

function _escape(str) {
  if (typeof str !== 'string') return String(str ?? '');
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
