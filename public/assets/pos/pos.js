// Rasentra POS: perilaku layar sentuh kasir. Tanpa framework dan tanpa CDN agar tetap
// berjalan di server outlet saat internet putus. Semua hitungan uang dilakukan server;
// klien hanya menampilkan string desimal yang dikirim API.
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const csrf = $('meta[name="csrf-token"]').content;
  const state = { boot: null, order: null, lines: [], payload: null, channel: 'retail', category: null };

  const rupiah = (v) => 'Rp' + Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });
  const num = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });
  const qty = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---- API ---------------------------------------------------------------
  class ApiError extends Error {
    constructor(status, body) {
      super(body.message || 'Terjadi kesalahan.');
      this.status = status;
      this.code = body.error || null;
      this.context = body.context || {};
      this.errors = body.errors || null;
    }
  }

  async function api(method, path, body) {
    let res;
    try {
      res = await fetch('/pos/api/' + path, {
        method,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      setNet(false);
      throw new ApiError(0, { message: 'Server outlet tidak terjangkau. Periksa jaringan LAN.' });
    }
    setNet(true);
    if (res.status === 401 || res.status === 419) {
      location.href = '/pos/login';
      throw new ApiError(res.status, { message: 'Sesi berakhir.' });
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      if (data.errors) data.message = Object.values(data.errors).flat()[0];
      throw new ApiError(res.status, data);
    }
    return data;
  }

  // Aksi yang butuh persetujuan: bila server menjawab approval_required/approval_invalid,
  // tampilkan lembar PIN kepala outlet lalu ulangi permintaan dengan approval.
  async function withApproval(title, run) {
    try {
      return await run(null);
    } catch (e) {
      if (!['approval_required', 'approval_invalid'].includes(e.code)) throw e;
      const approval = await askApproval(title, e.code === 'approval_invalid' ? e.message : null);
      if (!approval) return null;
      return withApprovalRetry(title, run, approval);
    }
  }

  async function withApprovalRetry(title, run, approval) {
    try {
      return await run(approval);
    } catch (e) {
      if (e.code !== 'approval_invalid') throw e;
      const again = await askApproval(title, e.message);
      return again ? withApprovalRetry(title, run, again) : null;
    }
  }

  // ---- UI dasar ------------------------------------------------------------
  let toastTimer;
  function toast(msg, kind = '') {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast ' + kind;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, kind === 'err' ? 4500 : 2500);
  }

  const fail = (e) => toast(e.message, 'err');

  function setNet(ok) {
    const el = $('#net-status');
    el.textContent = ok ? 'LAN' : 'Terputus';
    el.style.background = ok ? '' : 'var(--red-soft)';
    el.style.color = ok ? '' : 'var(--red)';
  }

  let sheetClose = null;
  function openSheet(title, html, onClose) {
    $('#sheet-title').textContent = title;
    $('#sheet-content').innerHTML = html;
    $('#sheet').hidden = false;
    sheetClose = onClose || null;
    return $('#sheet-content');
  }

  function closeSheet() {
    $('#sheet').hidden = true;
    $('#sheet-content').innerHTML = '';
    const cb = sheetClose;
    sheetClose = null;
    if (cb) cb();
    focusScan();
  }

  function focusScan() {
    if ($('#sheet').hidden) $('#scan').focus({ preventScroll: true });
  }

  // Keypad angka sentuh yang mengisi sebuah input. mode 'pin' menyamarkan isi.
  function keypad(target, { decimal = false, onEnter } = {}) {
    const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', decimal ? '.' : 'C', '0', '⌫'];
    const pad = document.createElement('div');
    pad.className = 'keypad';
    pad.innerHTML = keys.map((k) => `<button type="button" data-k="${k}" class="${k === 'C' || k === '⌫' ? 'soft' : ''}">${k}</button>`).join('')
      + (onEnter ? '<button type="button" data-k="ok" class="soft" style="grid-column:1/-1">OK</button>' : '');
    pad.addEventListener('click', (ev) => {
      const k = ev.target.closest('button')?.dataset.k;
      if (!k) return;
      if (k === 'ok') return onEnter();
      if (k === 'C') target.value = '';
      else if (k === '⌫') target.value = target.value.slice(0, -1);
      else if (k === '.' && target.value.includes('.')) return;
      else target.value += k;
      target.dispatchEvent(new Event('input'));
    });
    return pad;
  }

  function askApproval(title, error) {
    return new Promise((resolve) => {
      const approvers = state.boot.approvers || [];
      if (!approvers.length) {
        toast('Tidak ada kepala outlet aktif yang bisa menyetujui.', 'err');
        return resolve(null);
      }
      let chosen = approvers[0].id;
      let done = false;
      const root = openSheet('Persetujuan: ' + title, `
        <p class="muted">Kepala outlet memasukkan PIN sendiri. Penyetuju harus orang lain, bukan kasir.</p>
        ${error ? `<p class="error">${esc(error)}</p>` : ''}
        <div class="pay-grid">
          <div><div class="field"><label>Penyetuju</label><div class="options" id="ap-who">
            ${approvers.map((a, i) => `<button type="button" data-id="${a.id}" class="${i === 0 ? 'on' : ''}">${esc(a.name)}</button>`).join('')}
          </div></div>
          <div class="field"><label>Alasan</label><input id="ap-reason" placeholder="Wajib diisi untuk audit"></div></div>
          <div><input id="ap-pin" class="pin-display" type="password" inputmode="none" readonly style="width:100%"><div id="ap-pad"></div></div>
        </div>`, () => { if (!done) resolve(null); });
      root.querySelector('#ap-who').addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b) return;
        chosen = Number(b.dataset.id);
        root.querySelectorAll('#ap-who button').forEach((x) => x.classList.toggle('on', x === b));
      });
      const pin = root.querySelector('#ap-pin');
      root.querySelector('#ap-pad').appendChild(keypad(pin, {
        onEnter: () => {
          if (pin.value.length < 4) return toast('PIN minimal 4 angka.', 'err');
          done = true;
          const reason = root.querySelector('#ap-reason').value.trim() || title;
          closeSheet();
          resolve({ approver_id: chosen, pin: pin.value, reason });
        },
      }));
    });
  }

  // ---- Bootstrap & katalog -------------------------------------------------
  async function boot() {
    try {
      state.boot = await api('GET', 'bootstrap');
    } catch (e) {
      return fail(e);
    }
    const b = state.boot;
    $('#outlet-info').textContent = `${b.outlet.code} · ${b.outlet.name} · ${b.outlet.region} · ${b.user.name}`;
    $('#channel').querySelectorAll('button').forEach((btn) => {
      if (btn.dataset.channel !== 'retail' && !b.outlet.has_restaurant) btn.hidden = true;
    });
    renderHeld();
    renderCategories();
    loadCatalog();
    if (!b.shift) openShiftSheet();
    focusScan();
  }

  function renderCategories() {
    const cats = [null, ...state.boot.categories];
    $('#categories').innerHTML = cats.map((c) => `<button type="button" data-cat="${esc(c ?? '')}" class="${c === state.category ? 'on' : ''}">${esc(c ?? 'Semua')}</button>`).join('');
  }

  async function loadCatalog() {
    const q = state.category ? '?category=' + encodeURIComponent(state.category) : '';
    try {
      const items = await api('GET', 'catalog' + q);
      $('#products').innerHTML = items.map((p) => `
        <button type="button" class="tile ${p.is_weighed ? 'weighed' : ''}" data-product="${p.id}" data-weighed="${p.is_weighed ? 1 : 0}">
          <b>${esc(p.name)}</b>
          <span class="price">${p.price === null ? '—' : rupiah(p.price)}${p.is_weighed ? '/kg' : ''}</span>
          <span class="meta">${esc(p.code)}${p.is_weighed ? ` · ${p.available_packs} pack siap · pindai Pack ID` : ''}</span>
        </button>`).join('') || '<p class="muted">Tidak ada produk.</p>';
    } catch (e) {
      fail(e);
    }
  }

  function renderHeld() {
    const n = state.boot.held.length;
    $('#held-count').textContent = n;
  }

  // ---- Pesanan -------------------------------------------------------------
  async function ensureOrder() {
    if (state.order && ['draft', 'confirmed'].includes(state.order.status)) return state.order;
    const data = await api('POST', 'orders', { channel: state.channel });
    applyOrder(data);
    return state.order;
  }

  function applyOrder(data) {
    state.payload = data;
    state.order = data.order;
    state.lines = data.lines;
    if (data.order.channel) setChannel(data.order.channel, false);
    renderCart();
  }

  function resetOrder() {
    state.order = null;
    state.lines = [];
    state.payload = null;
    renderCart();
    focusScan();
  }

  function setChannel(ch, user = true) {
    if (user && state.lines.length) return toast('Ganti kanal hanya untuk pesanan kosong. Tekan Baru dulu.', 'err');
    state.channel = ch;
    $('#channel').querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.channel === ch));
    $('#btn-kitchen').hidden = ch === 'retail';
    if (user && state.order && !state.lines.length) state.order = null; // pesanan kosong dibuat ulang dengan kanal baru
  }

  function renderCart() {
    const o = state.order;
    $('#order-no').textContent = o?.doc_no || '';
    const ul = $('#lines');
    if (!state.lines.length) {
      ul.innerHTML = '<li class="empty">Pindai produk atau sentuh menu untuk mulai.</li>';
    } else {
      ul.innerHTML = state.lines.map((l) => {
        const disc = Number(l.discount_amount) > 0;
        const tags = [
          l.line_type === 'cooking_service' ? 'jasa masak' : '',
          l.cooking ? `dimasak · ${l.cooking.status}` : '',
          Number(l.net_amount) === 0 && l.line_type !== 'cooking_service' ? 'gratis' : '',
        ].filter(Boolean).map((t) => `<span class="tag">${esc(t)}</span>`).join('');
        return `<li data-line="${l.id}">
          <span class="name">${esc(l.name)}${tags}</span>
          <span class="amt">${num(l.gross_amount)}</span>
          <span class="sub">${l.pack_code ? esc(l.pack_code) + ' · ' : ''}${qty(l.quantity)} ${esc(l.uom)} × ${num(l.unit_price)}${l.note ? ' · ' + esc(l.note) : ''}</span>
          ${disc ? `<span class="promo">Promo −${num(l.discount_amount)} → ${num(l.net_amount)}</span>` : ''}
        </li>`;
      }).join('');
      ul.scrollTop = ul.scrollHeight;
    }
    $('#t-gross').textContent = num(o?.gross_amount);
    $('#t-disc').textContent = Number(o?.discount_amount) > 0 ? '−' + num(o.discount_amount) : '0';
    $('#t-net').textContent = rupiah(o?.net_amount);
    $('#btn-pay').disabled = !state.lines.length;
  }

  async function scan(code) {
    code = code.trim();
    if (!code) return;
    try {
      await ensureOrder();
      const data = await withApproval('Kemasan butuh persetujuan', (approval) =>
        api('POST', `orders/${state.order.id}/scan`, { code, approval }));
      if (data) {
        applyOrder(data);
        toast('Ditambahkan: ' + code, 'ok');
      }
    } catch (e) {
      fail(e);
    }
  }

  async function addProduct(id, weighed) {
    if (weighed) return toast('Produk timbang dijual per Pack ID. Pindai label kemasannya.', 'err');
    try {
      await ensureOrder();
      applyOrder(await api('POST', `orders/${state.order.id}/items`, { product_id: id, quantity: 1 }));
    } catch (e) {
      fail(e);
    }
  }

  // ---- Lembar aksi baris ---------------------------------------------------
  function lineSheet(lineId) {
    const l = state.lines.find((x) => x.id === lineId);
    if (!l) return;
    const canCook = state.boot.outlet.has_cooking_fee && l.pack_code && !l.cooking && l.line_type !== 'cooking_service';
    const root = openSheet(l.name, `
      <div class="summary">
        <div><span>${l.pack_code ? 'Pack ID ' + esc(l.pack_code) : 'Kuantitas'}</span><b class="mono">${qty(l.quantity)} ${esc(l.uom)}</b></div>
        <div><span>Harga satuan</span><b class="mono">${num(l.unit_price)}</b></div>
        <div class="big"><span>Bersih</span><b class="mono">${rupiah(l.net_amount)}</b></div>
      </div>
      <div class="action-list">
        ${canCook ? '<button class="btn soft" data-do="cook">Masak di resto (cooking fee)</button>' : ''}
        <button class="btn soft" data-do="price">Ubah harga (butuh persetujuan)</button>
        <button class="btn danger" data-do="void">Batalkan baris</button>
      </div>`);
    root.addEventListener('click', (ev) => {
      const act = ev.target.closest('[data-do]')?.dataset.do;
      if (act === 'void') voidLine(l);
      if (act === 'price') priceSheet(l);
      if (act === 'cook') cookSheet(l);
    });
  }

  async function voidLine(l) {
    closeSheet();
    try {
      const data = await withApproval('Batalkan ' + l.name, (approval) =>
        api('POST', `lines/${l.id}/void`, { reason: approval?.reason || 'Dibatalkan kasir', approval }));
      if (data) applyOrder(data);
    } catch (e) {
      fail(e);
    }
  }

  function priceSheet(l) {
    const root = openSheet('Ubah harga: ' + l.name, `
      <div class="pay-grid">
        <div><div class="field"><label>Harga satuan baru (${esc(l.uom)})</label><input id="np" class="amount-display" inputmode="none" readonly value=""></div>
        <div class="field"><label>Alasan</label><input id="nr" placeholder="Contoh: kemasan rusak ringan"></div></div>
        <div id="np-pad"></div>
      </div>`);
    const input = root.querySelector('#np');
    root.querySelector('#np-pad').appendChild(keypad(input, {
      decimal: true,
      onEnter: async () => {
        const reason = root.querySelector('#nr').value.trim();
        if (!input.value || !reason) return toast('Isi harga dan alasan.', 'err');
        closeSheet();
        try {
          const data = await withApproval('Ubah harga ' + l.name, (approval) =>
            approval ? api('POST', `lines/${l.id}/price`, { unit_price: input.value, reason, approval })
              : Promise.reject(new ApiError(403, { error: 'approval_required', message: 'Butuh persetujuan.' })));
          if (data) applyOrder(data);
        } catch (e) {
          fail(e);
        }
      },
    }));
  }

  function cookSheet(l) {
    // Metode mengikuti tarif cooking fee yang berlaku di outlet (null = tarif standar).
    const METHOD_LABEL = { '': 'Standar / grill', bakar: 'Bakar', goreng: 'Goreng', saus_padang: 'Saus Padang', grill: 'Grill', pan: 'Pan sear' };
    const methods = (state.boot.cooking_methods || []).map((r) => r.method ?? '');
    if (!methods.length) methods.push('');
    const pick = { portions: 1, doneness: 'medium', method: methods[0], sauce: null };
    const opt = (key, values) => `<div class="options" data-key="${key}">${values.map(([v, t]) =>
      `<button type="button" data-v="${v}" class="${String(pick[key]) === String(v) ? 'on' : ''}">${t}</button>`).join('')}</div>`;
    const root = openSheet('Masak di resto: ' + l.name, `
      <p class="muted">Daging atau ikan tetap dijual sebagai baris retail (Pack ID ${esc(l.pack_code)}). Jasa memasak ditambahkan sebagai baris terpisah sesuai tarif metode.</p>
      <div class="field"><label>Metode</label>${opt('method', methods.map((m) => [m, METHOD_LABEL[m] || m]))}</div>
      <div class="field"><label>Kematangan</label>${opt('doneness', [['rare', 'Rare'], ['medium_rare', 'Medium rare'], ['medium', 'Medium'], ['well_done', 'Well done']])}</div>
      <div class="field"><label>Porsi</label>${opt('portions', [[1, '1'], [2, '2'], [3, '3'], [4, '4']])}</div>
      <div class="field"><label>Saus / sambal</label>${opt('sauce', [['lada hitam', 'Lada hitam'], ['jamur', 'Jamur'], ['sambal matah', 'Sambal matah'], ['sambal kecap', 'Sambal kecap']])}</div>
      <div class="field"><label>Catatan alergi</label><input id="allergy" placeholder="Opsional"></div>
      <button class="btn primary xl block" data-do="send">Tambah jasa memasak</button>`);
    root.addEventListener('click', async (ev) => {
      const b = ev.target.closest('.options button');
      if (b) {
        const group = b.parentElement;
        pick[group.dataset.key] = b.dataset.v;
        group.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
        return;
      }
      if (ev.target.closest('[data-do="send"]')) {
        const body = { ...pick, method: pick.method || null, portions: Number(pick.portions), allergy_note: root.querySelector('#allergy').value || null };
        closeSheet();
        try {
          applyOrder(await api('POST', `lines/${l.id}/cooking`, body));
          toast('Jasa memasak ditambahkan.', 'ok');
        } catch (e) {
          fail(e);
        }
      }
    });
  }

  // ---- Pembayaran ----------------------------------------------------------
  const METHODS = [['cash', 'Tunai'], ['qris', 'QRIS'], ['edc', 'EDC'], ['edc_manual', 'EDC manual'], ['voucher', 'Voucher']];

  function paySheet() {
    if (!state.order || !state.lines.length) return;
    if (!state.boot.shift) return openShiftSheet();
    let method = 'cash';
    const render = () => {
      const p = state.payload;
      const remaining = Number(p.remaining);
      const quick = quickCash(remaining);
      const root = openSheet('Pembayaran ' + (state.order.doc_no || ''), `
        <div class="pay-grid">
          <div>
            <div class="summary">
              <div><span>Total</span><b class="mono">${rupiah(state.order.net_amount)}</b></div>
              <div><span>Sudah dibayar</span><b class="mono">${rupiah(p.paid)}</b></div>
              <div class="big"><span>Sisa</span><b class="mono">${rupiah(p.remaining)}</b></div>
            </div>
            ${p.payments.map((x) => `<div class="muted mono">${esc(x.method)} ${num(x.amount)} · ${esc(x.status)}${x.change_amount ? ' · kembali ' + num(x.change_amount) : ''}
              ${['pending', 'uncertain'].includes(x.status) ? `<button class="btn ghost" data-check="${x.id}">Cek status</button>` : ''}</div>`).join('')}
            <div class="methods" style="margin-top:12px">${METHODS.map(([v, t]) => `<button type="button" data-m="${v}" class="${v === method ? 'on' : ''}">${t}</button>`).join('')}</div>
            <div class="field" id="ref-field" style="margin-top:12px" ${method === 'cash' ? 'hidden' : ''}><label>No. referensi / approval code</label><input id="pay-ref"></div>
          </div>
          <div>
            <input id="pay-amt" class="amount-display" inputmode="none" readonly value="${remaining > 0 ? p.remaining.replace(/\.00$/, '') : ''}">
            <div class="quick">${quick.map((v) => `<button type="button" data-q="${v}">${num(v)}</button>`).join('')}</div>
            <div id="pay-pad"></div>
            <button class="btn primary xl block" data-do="pay" style="margin-top:10px" ${remaining <= 0 ? 'hidden' : ''}>Terima pembayaran</button>
            <button class="btn primary xl block" data-do="finish" style="margin-top:10px" ${remaining > 0 ? 'hidden' : ''}>Selesaikan transaksi</button>
          </div>
        </div>`);
      const amt = root.querySelector('#pay-amt');
      root.querySelector('#pay-pad').appendChild(keypad(amt, { decimal: true }));
      root.onclick = async (ev) => {
        const m = ev.target.closest('[data-m]');
        if (m) { method = m.dataset.m; return render(); }
        const q = ev.target.closest('[data-q]');
        if (q) { amt.value = q.dataset.q; return; }
        const chk = ev.target.closest('[data-check]');
        if (chk) return checkPayment(chk.dataset.check, render);
        const act = ev.target.closest('[data-do]')?.dataset.do;
        if (act === 'pay') return takePayment(method, amt.value, root.querySelector('#pay-ref')?.value, render);
        if (act === 'finish') return finish();
      };
    };
    render();
  }

  // Tombol uang pas + pecahan rupiah terdekat di atas sisa tagihan.
  function quickCash(remaining) {
    if (remaining <= 0) return [];
    const out = [Math.ceil(remaining)];
    for (const step of [10000, 50000, 100000]) {
      const v = Math.ceil(remaining / step) * step;
      if (!out.includes(v)) out.push(v);
    }
    return out.slice(0, 3);
  }

  async function takePayment(method, value, ref, rerender) {
    if (!value || Number(value) <= 0) return toast('Masukkan nominal.', 'err');
    const remaining = Number(state.payload.remaining);
    const body = { method, amount: value };
    if (method === 'cash') {
      body.tendered = value;
      body.amount = String(Math.min(Number(value), remaining));
    } else if (ref) {
      body.provider_ref = ref;
    }
    try {
      const data = await api('POST', `orders/${state.order.id}/payments`, body);
      applyOrder(data);
      const last = data.payments[data.payments.length - 1];
      if (last?.change_amount && Number(last.change_amount) > 0) toast('Kembalian ' + rupiah(last.change_amount), 'ok');
      if (last?.status === 'pending') toast('Menunggu konfirmasi ' + method.toUpperCase() + '. Tekan Cek status.', '');
      rerender();
    } catch (e) {
      fail(e);
    }
  }

  async function checkPayment(id, rerender) {
    try {
      const p = await api('GET', 'payments/' + id);
      toast('Status pembayaran: ' + p.status, p.status === 'succeeded' ? 'ok' : '');
      applyOrder(await api('GET', 'orders/' + state.order.id));
      rerender();
    } catch (e) {
      fail(e);
    }
  }

  async function finish() {
    try {
      const data = await api('POST', `orders/${state.order.id}/finalize`);
      closeSheet();
      toast(`Transaksi ${data.order.doc_no} selesai.`, 'ok');
      resetOrder();
      loadCatalog();
    } catch (e) {
      fail(e);
    }
  }

  // ---- Tahan, lanjutkan, dapur ---------------------------------------------
  async function hold() {
    if (!state.order || !state.lines.length) return;
    try {
      const data = await api('POST', `orders/${state.order.id}/hold`);
      state.boot.held.unshift({ id: data.order.id, net_amount: data.order.net_amount, channel: data.order.channel, created_at: new Date().toISOString() });
      renderHeld();
      toast('Transaksi ditahan.', 'ok');
      resetOrder();
    } catch (e) {
      fail(e);
    }
  }

  function heldSheet() {
    const list = state.boot.held;
    const root = openSheet('Transaksi ditahan', list.length
      ? `<div class="held-list">${list.map((h) => `<button type="button" data-id="${h.id}">
          <span>${esc(h.channel)} · ${new Date(h.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span>
          <b class="mono">${rupiah(h.net_amount)}</b></button>`).join('')}</div>`
      : '<p class="muted">Belum ada transaksi yang ditahan.</p>');
    root.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-id]');
      if (!b) return;
      if (state.lines.length) return toast('Tahan atau selesaikan pesanan aktif dulu.', 'err');
      try {
        applyOrder(await api('POST', `orders/${b.dataset.id}/resume`));
        state.boot.held = state.boot.held.filter((h) => h.id !== b.dataset.id);
        renderHeld();
        closeSheet();
      } catch (e) {
        fail(e);
      }
    });
  }

  async function sendKitchen() {
    if (!state.order || !state.lines.length) return;
    try {
      const data = await api('POST', `orders/${state.order.id}/kitchen`);
      applyOrder(data);
      toast(data.tickets ? `${data.tickets} tiket dikirim ke dapur.` : 'Tidak ada item baru untuk dapur.', 'ok');
    } catch (e) {
      fail(e);
    }
  }

  // ---- Shift ---------------------------------------------------------------
  function openShiftSheet() {
    const root = openSheet('Buka shift', `
      <p class="muted">Hitung modal kas awal di laci sebelum transaksi pertama.</p>
      <div class="pay-grid"><input id="open-cash" class="amount-display" inputmode="none" readonly value="">
      <div id="open-pad"></div></div>`);
    const input = root.querySelector('#open-cash');
    root.querySelector('#open-pad').appendChild(keypad(input, {
      onEnter: async () => {
        try {
          state.boot.shift = await api('POST', 'shift/open', { opening_cash: input.value || '0' });
          closeSheet();
          toast('Shift dibuka.', 'ok');
        } catch (e) {
          fail(e);
        }
      },
    }));
  }

  async function shiftSheet() {
    if (!state.boot.shift) return openShiftSheet();
    let summary;
    try {
      summary = await api('GET', 'shift');
    } catch (e) {
      return fail(e);
    }
    const ex = summary.expected;
    const root = openSheet('Shift saya', `
      <div class="summary">${Object.entries(ex).map(([k, v]) => `<div><span>${esc(k)}</span><b class="mono">${rupiah(v)}</b></div>`).join('')}</div>
      <div class="action-list">
        <button class="btn soft" data-do="cash_in">Kas masuk</button>
        <button class="btn soft" data-do="cash_out">Kas keluar</button>
        <button class="btn soft" data-do="no_sale_open">Buka laci tanpa transaksi</button>
        <button class="btn danger" data-do="close">Tutup shift (hitung kas)</button>
      </div>`);
    root.addEventListener('click', (ev) => {
      const act = ev.target.closest('[data-do]')?.dataset.do;
      if (act === 'close') closeShiftSheet();
      else if (act) cashSheet(act);
    });
  }

  function cashSheet(type) {
    const titles = { cash_in: 'Kas masuk', cash_out: 'Kas keluar', no_sale_open: 'Buka laci' };
    const root = openSheet(titles[type], `
      <div class="pay-grid"><div>
        ${type === 'no_sale_open' ? '' : '<input id="cm-amt" class="amount-display" inputmode="none" readonly value="">'}
        <div class="field" style="margin-top:12px"><label>Alasan</label><input id="cm-reason"></div></div>
        <div id="cm-pad"></div></div>`);
    const amt = root.querySelector('#cm-amt') || document.createElement('input');
    const submit = async () => {
      const reason = root.querySelector('#cm-reason').value.trim();
      if (!reason) return toast('Alasan wajib diisi.', 'err');
      try {
        await api('POST', 'shift/cash', { type, amount: amt.value || '0', reason });
        closeSheet();
        toast(titles[type] + ' dicatat.', 'ok');
      } catch (e) {
        fail(e);
      }
    };
    root.querySelector('#cm-pad').appendChild(keypad(amt, { onEnter: submit }));
  }

  function closeShiftSheet() {
    const root = openSheet('Tutup shift', `
      <p class="muted">Hitung uang tunai fisik di laci. Selisih di atas batas butuh persetujuan kepala outlet.</p>
      <div class="pay-grid"><input id="cl-cash" class="amount-display" inputmode="none" readonly value=""><div id="cl-pad"></div></div>`);
    const input = root.querySelector('#cl-cash');
    root.querySelector('#cl-pad').appendChild(keypad(input, {
      onEnter: async () => {
        const counts = { cash: input.value || '0' };
        closeSheet();
        try {
          const shift = await withApproval('Selisih kas tutup shift', (approval) => api('POST', 'shift/close', { counts, approval }));
          if (!shift) return;
          state.boot.shift = null;
          toast('Shift ditutup. Selisih kas: ' + rupiah(shift.cash_difference ?? 0), 'ok');
          setTimeout(() => document.querySelector('form[action$="logout"]').submit(), 1500);
        } catch (e) {
          fail(e);
        }
      },
    }));
  }

  // ---- Event ---------------------------------------------------------------
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('[data-action]');
    if (a) {
      const act = a.dataset.action;
      if (act === 'close') return closeSheet();
      if (act === 'held') return heldSheet();
      if (act === 'shift') return shiftSheet();
      if (act === 'scan-submit') { const v = $('#scan').value; $('#scan').value = ''; return scan(v); }
      if (act === 'new') { if (state.lines.length) return toast('Tahan atau batalkan baris dulu.', 'err'); return resetOrder(); }
      if (act === 'hold') return hold();
      if (act === 'kitchen') return sendKitchen();
      if (act === 'pay') return paySheet();
    }
    const ch = ev.target.closest('#channel button');
    if (ch) return setChannel(ch.dataset.channel);
    const cat = ev.target.closest('#categories button');
    if (cat) {
      state.category = cat.dataset.cat || null;
      renderCategories();
      return loadCatalog();
    }
    const tile = ev.target.closest('[data-product]');
    if (tile) return addProduct(Number(tile.dataset.product), tile.dataset.weighed === '1');
    const line = ev.target.closest('#lines li[data-line]');
    if (line) return lineSheet(line.dataset.line);
    if (ev.target === $('#sheet')) closeSheet();
  });

  // Pemindai barcode (keyboard wedge) mengetik ke #scan lalu Enter.
  $('#scan').addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    const v = ev.target.value;
    ev.target.value = '';
    scan(v);
  });

  // Fokus dikembalikan ke kolom pindai agar pemindai selalu siap setelah sentuhan.
  document.addEventListener('pointerup', (ev) => {
    if (!ev.target.closest('input, #sheet')) setTimeout(focusScan, 0);
  });
  window.addEventListener('online', () => setNet(true));
  window.addEventListener('offline', () => setNet(false));

  boot();
})();
