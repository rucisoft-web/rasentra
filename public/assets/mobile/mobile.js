// Rasentra Mobile Terminal: tablet pelayan. Peta meja, buka meja, catat menu dengan modifier,
// kirim dapur per gelombang, antar hidangan siap, masak daging tamu, lalu minta bayar.
// Setiap permintaan meja memakai token kunjungan, jadi pesanan tidak masuk ke tamu sebelumnya.
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rupiah = (v) => 'Rp' + Math.round(Number(v || 0)).toLocaleString('id-ID');
  const qty = (v) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: 3 });
  const mins = (iso) => Math.max(0, Math.floor((Date.now() + skew - Date.parse(iso)) / 60000));
  const csrf = $('meta[name="csrf-token"]').content;
  let skew = 0;

  const STATUS = { unsent: 'Belum dikirim', queued: 'Antre', in_preparation: 'Dimasak', ready: 'Siap diantar', served: 'Disajikan', cancel_requested: 'Minta batal' };
  const COURSE = { starter: 'Pembuka', main: 'Utama', dessert: 'Penutup' };

  const state = { boot: null, tables: [], area: null, menu: [], cat: null, q: '', token: null, detail: null, pane: 'menu' };

  async function api(method, path, body) {
    const res = await fetch('/mobile/api/' + path, {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 419) {
      location.href = '/pos/login';
      throw new Error('Sesi berakhir.');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.message || 'Gagal.');
      err.code = data.error;
      throw err;
    }
    if (data.server_time) skew = Date.parse(data.server_time) - Date.now();
    return data;
  }

  function toast(msg, kind) {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast' + (kind ? ' ' + kind : '');
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, 2800);
  }

  function fail(e) {
    if (e.code === 'visit_closed' || e.code === 'visit_unknown') {
      toast(e.message, 'err');
      showMap();
      return;
    }
    toast(e.message, 'err');
  }

  function net(ok) {
    $('#net').textContent = ok ? 'LAN' : 'OFFLINE';
    $('#net').classList.toggle('off', !ok);
  }

  // ---- Dialog ---------------------------------------------------------------
  function dialog(html) {
    const wrap = $('#dialog');
    const old = $('.dialog-body', wrap);
    const body = old.cloneNode(false);
    old.replaceWith(body);
    body.innerHTML = html;
    wrap.hidden = false;
    return body;
  }
  const closeDialog = () => { $('#dialog').hidden = true; };
  $('#dialog').addEventListener('click', (ev) => { if (ev.target.id === 'dialog' || ev.target.closest('[data-d="cancel"]')) closeDialog(); });

  const stepper = (id, val) => `<div class="stepper" id="${id}"><button type="button" data-step="-1">−</button><b>${val}</b><button type="button" data-step="1">+</button></div>`;
  function bindStepper(root, id, min, max, onChange) {
    const el = $('#' + id, root);
    el.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-step]');
      if (!b) return;
      const v = Math.min(max, Math.max(min, Number($('b', el).textContent) + Number(b.dataset.step)));
      $('b', el).textContent = v;
      if (onChange) onChange(v);
    });
    return () => Number($('b', el).textContent);
  }
  function bindOpts(root, sel, multiple) {
    root.querySelectorAll(sel).forEach((group) => group.addEventListener('click', (ev) => {
      const b = ev.target.closest('button');
      if (!b) return;
      const multi = multiple || group.dataset.multiple === '1';
      if (!multi) group.querySelectorAll('button').forEach((x) => { if (x !== b) x.classList.remove('on'); });
      b.classList.toggle('on', multi ? !b.classList.contains('on') : true);
    }));
  }

  // ---- Peta meja ------------------------------------------------------------
  async function loadTables() {
    try {
      const data = await api('GET', 'tables');
      state.tables = data.tables;
      net(true);
      renderMap();
    } catch (e) {
      net(false);
    }
  }

  function renderMap() {
    const areas = [...new Set(state.tables.map((t) => t.area))];
    $('#areas').innerHTML = [null, ...areas].map((a) => `<button type="button" data-area="${esc(a ?? '')}" class="${(a ?? null) === state.area ? 'on' : ''}">${esc(a ?? 'Semua area')}</button>`).join('');
    const shown = areas.filter((a) => state.area === null || a === state.area);
    $('#tables').innerHTML = shown.map((a) => `<div class="area"><h3>${esc(a)}</h3><div class="grid">${state.tables.filter((t) => t.area === a).map(tableTile).join('')}</div></div>`).join('')
      || '<p class="empty">Belum ada meja. Tambahkan meja di Rasentra Central.</p>';
  }

  function tableTile(t) {
    const v = t.visit;
    let flag = '';
    if (v && v.ready) flag = `<span class="flag ready">${v.ready} siap</span>`;
    else if (t.status === 'billing') flag = '<span class="flag bill">Minta bayar</span>';
    else if (v && v.unsent) flag = `<span class="flag unsent">${v.unsent} belum dikirim</span>`;
    return `<button type="button" class="table ${t.status}" data-table="${t.id}">
      ${flag}
      <b>${esc(t.code)}</b>
      ${v ? `<span class="line">${v.guests} tamu · ${mins(v.opened_at)} mnt</span><span class="line mono">${rupiah(v.net_amount)}</span><span class="cap">${esc(v.waiter || '')}</span>`
        : `<span class="cap">Kosong · ${t.capacity} kursi</span>`}
    </button>`;
  }

  $('#areas').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-area]');
    if (!b) return;
    state.area = b.dataset.area || null;
    renderMap();
  });

  $('#tables').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-table]');
    if (!b) return;
    const t = state.tables.find((x) => x.id === Number(b.dataset.table));
    if (t.visit) return openTable(t.visit.token);
    const root = dialog(`<h2>Buka meja ${esc(t.code)}</h2><p class="muted">${esc(t.area)} · ${t.capacity} kursi</p>
      <div class="field"><label>Jumlah tamu</label>${stepper('d-guests', Math.min(2, t.capacity))}</div>
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button><button class="btn primary" data-d="ok">Buka meja</button></div>`);
    const guests = bindStepper(root, 'd-guests', 1, 50);
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      try {
        const data = await api('POST', `tables/${t.id}/open`, { guests: guests() });
        closeDialog();
        showTable(data);
      } catch (e) {
        fail(e);
        loadTables();
      }
    });
  });

  function showMap() {
    state.token = null;
    state.detail = null;
    $('#view-table').hidden = true;
    $('#view-map').hidden = false;
    loadTables();
  }

  // ---- Meja: menu dan pesanan ---------------------------------------------------
  async function openTable(token) {
    try {
      showTable(await api('GET', 'visits/' + token));
    } catch (e) {
      fail(e);
    }
  }

  function showTable(detail) {
    state.token = detail.visit.token;
    $('#view-map').hidden = true;
    $('#view-table').hidden = false;
    $('#m-search').value = '';
    state.q = '';
    setPane('menu');
    applyDetail(detail);
    renderMenu();
  }

  function applyDetail(d) {
    state.detail = d;
    const v = d.visit;
    $('#t-code').textContent = 'Meja ' + v.table.code;
    $('#t-meta').textContent = `${v.table.area || ''} · ${v.guests} tamu · ${mins(v.opened_at)} mnt · ${v.waiter || ''}`;
    $('#o-count').textContent = d.lines.length;
    renderOrder();
  }

  function setPane(p) {
    state.pane = p;
    $('.split').dataset.paneShow = p;
    document.querySelectorAll('#pane-switch button').forEach((b) => b.classList.toggle('on', b.dataset.pane === p));
  }
  $('#pane-switch').addEventListener('click', (ev) => { const b = ev.target.closest('[data-pane]'); if (b) setPane(b.dataset.pane); });

  async function loadMenu() {
    try {
      state.menu = await api('GET', 'menu');
    } catch (e) {
      fail(e);
    }
  }

  function renderMenu() {
    const cats = [null, ...state.boot.categories];
    $('#m-cats').innerHTML = cats.map((c) => `<button type="button" data-cat="${esc(c ?? '')}" class="${(c ?? null) === state.cat ? 'on' : ''}">${esc(c ?? 'Semua')}</button>`).join('');
    const q = state.q.toLowerCase();
    const items = state.menu.filter((m) => (state.cat === null || m.category === state.cat) && (!q || m.name.toLowerCase().includes(q)));
    $('#m-grid').innerHTML = items.map((m) => `<button type="button" class="mtile" data-menu="${m.id}"><b>${esc(m.name)}</b><span>${rupiah(m.price)}</span></button>`).join('')
      || '<p class="empty">Menu tidak ditemukan.</p>';
  }

  $('#m-cats').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-cat]');
    if (!b) return;
    state.cat = b.dataset.cat || null;
    renderMenu();
  });
  $('#m-search').addEventListener('input', (ev) => { state.q = ev.target.value.trim(); renderMenu(); });

  // Pilih menu: modifier, jumlah, gelombang (course) dan catatan.
  $('#m-grid').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-menu]');
    if (!b) return;
    const m = state.menu.find((x) => x.id === Number(b.dataset.menu));
    const groups = state.boot.modifiers[m.category] || [];
    const root = dialog(`<h2>${esc(m.name)}</h2><p class="muted mono">${rupiah(m.price)}</p>
      ${groups.map((g) => `<div class="field"><label>${esc(g.label)}${g.required ? ' *' : ''}</label>
        <div class="opts" data-group="${esc(g.group)}" data-multiple="${g.multiple ? 1 : 0}" data-required="${g.required ? 1 : 0}">
          ${g.options.map(([k, l]) => `<button type="button" data-key="${esc(k)}">${esc(l)}</button>`).join('')}</div></div>`).join('')}
      <div class="field"><label>Jumlah</label>${stepper('d-qty', 1)}</div>
      <div class="field"><label>Disajikan</label><div class="opts" id="d-course">
        <button type="button" data-course="" class="on">Langsung</button>${Object.entries(COURSE).map(([k, l]) => `<button type="button" data-course="${k}">${l}</button>`).join('')}</div></div>
      <div class="field"><label>Catatan</label><div class="opts" id="d-notes">${state.boot.quick_notes.map((n) => `<button type="button">${esc(n)}</button>`).join('')}</div>
        <input id="d-note" maxlength="200" placeholder="Catatan untuk dapur (opsional)"></div>
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button><button class="btn primary" data-d="ok">Tambah · <span id="d-sum">${rupiah(m.price)}</span></button></div>`);
    bindOpts(root, '[data-group]');
    bindOpts(root, '#d-course');
    bindOpts(root, '#d-notes', true);
    const count = bindStepper(root, 'd-qty', 1, 99, (v) => { $('#d-sum', root).textContent = rupiah(v * Number(m.price)); });
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      const missing = [...root.querySelectorAll('[data-required="1"]')].find((g) => !g.querySelector('.on'));
      if (missing) return toast(missing.closest('.field').querySelector('label').textContent.replace(' *', '') + ' wajib dipilih.', 'err');
      const note = [...root.querySelectorAll('#d-notes .on')].map((x) => x.textContent).concat($('#d-note', root).value.trim() || []).join(', ');
      try {
        const data = await api('POST', `visits/${state.token}/items`, {
          product_id: m.id, quantity: count(),
          modifiers: [...root.querySelectorAll('[data-group] .on')].map((x) => x.dataset.key),
          course: $('#d-course .on', root).dataset.course || null,
          note: note || null,
        });
        closeDialog();
        applyDetail(data);
        toast(`${count()}× ${m.name} ditambahkan.`, 'ok');
      } catch (e) {
        fail(e);
      }
    });
  });

  function renderOrder() {
    const d = state.detail;
    const unsent = d.lines.filter((l) => l.status === 'unsent' && l.kitchen);
    const rest = d.lines.filter((l) => !(l.status === 'unsent' && l.kitchen));
    const line = (l) => `<button type="button" class="oline" data-line="${l.id}">
      <span class="q">${qty(l.quantity)}×</span><span class="n">${esc(l.name)}</span><span class="p">${rupiah(l.net_amount)}</span>
      <span class="sub">${l.kitchen ? `<span class="tag ${l.status}">${STATUS[l.status] || l.status}</span>` : ''}${l.course ? `<span class="tag course">${COURSE[l.course]}</span>` : ''}
        ${esc([...l.modifiers, l.note].filter(Boolean).join(' · '))}</span></button>`;
    $('#o-lines').innerHTML = d.lines.length
      ? (unsent.length ? `<h4>Belum dikirim ke dapur</h4>${unsent.map(line).join('')}` : '') + (rest.length ? `<h4>Pesanan</h4>${rest.map(line).join('')}` : '')
      : '<p class="empty">Belum ada pesanan. Pilih menu di sebelah kiri.</p>';

    const ready = d.lines.reduce((n, l) => n + l.ready_items.length, 0);
    const courses = [...new Set(unsent.map((l) => l.course).filter(Boolean))];
    const sendRow = unsent.length
      ? `<div class="o-row"><button class="btn primary" data-act="send">Kirim dapur (${unsent.length})</button>
          ${courses.length && courses.length + (unsent.some((l) => !l.course) ? 1 : 0) > 1 ? courses.map((c) => `<button class="btn soft" data-act="send" data-course="${c}">Kirim ${COURSE[c]}</button>`).join('') : ''}</div>`
      : '';
    $('#o-foot').innerHTML = `
      <div class="o-total"><span>Total <small>${d.order.discount_amount > 0 ? 'setelah promo' : ''}</small></span><span class="mono">${rupiah(d.order.net_amount)}</span></div>
      ${sendRow}
      ${ready ? `<button class="btn blue block" data-act="serve">Antar ${ready} hidangan siap</button>` : ''}
      ${d.visit.bill_requested ? '<div class="waiting">Menunggu pembayaran di kasir</div>'
        : `<div class="o-row"><button class="btn amber" data-act="bill" ${d.lines.length ? '' : 'disabled'}>Minta bayar</button>${d.lines.length ? '' : '<button class="btn" data-act="close">Tamu batal, tutup meja</button>'}</div>`}`;
    $('#btn-cook').hidden = !state.boot.outlet.has_cooking_fee;
  }

  // Baris pesanan: hapus sebelum dikirim, atau void dengan PIN kepala outlet setelah dikirim.
  $('#o-lines').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-line]');
    if (!b) return;
    const l = state.detail.lines.find((x) => x.id === b.dataset.line);
    const sent = l.status !== 'unsent';
    if (['served'].includes(l.status)) return toast('Hidangan sudah disajikan. Retur atau komplain diproses di kasir.', 'err');
    const reasons = ['Salah input', 'Tamu batal', 'Terlalu lama', 'Stok habis'];
    const root = dialog(`<h2>${sent ? 'Batalkan' : 'Hapus'} ${esc(l.name)}?</h2>
      <p class="muted">${qty(l.quantity)}× · ${rupiah(l.net_amount)}${sent ? ' · sudah di dapur, perlu PIN kepala outlet' : ''}</p>
      <div class="field"><label>Alasan</label><div class="opts" id="d-reason">${reasons.map((r, i) => `<button type="button" class="${i === 0 ? 'on' : ''}">${r}</button>`).join('')}</div></div>
      ${sent ? `<div class="field"><label>Disetujui oleh</label><select id="d-appr">${state.boot.approvers.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></div>
        <div class="field"><label>PIN</label><input id="d-pin" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>` : ''}
      <div class="d-acts"><button class="btn" data-d="cancel">Kembali</button><button class="btn danger" data-d="ok">${sent ? 'Batalkan item' : 'Hapus'}</button></div>`);
    bindOpts(root, '#d-reason');
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      const body = { reason: $('#d-reason .on', root).textContent };
      if (sent) body.approval = { approver_id: Number($('#d-appr', root).value), pin: $('#d-pin', root).value };
      try {
        applyDetail(await api('POST', `visits/${state.token}/lines/${l.id}/void`, body));
        closeDialog();
        toast(sent ? 'Permintaan batal dikirim ke dapur.' : 'Item dihapus.', 'ok');
      } catch (e) {
        fail(e);
      }
    });
  });

  document.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    try {
      if (act === 'back') return showMap();
      if (act === 'send') {
        const data = await api('POST', `visits/${state.token}/send`, { course: b.dataset.course || null });
        applyDetail(data);
        return toast(data.tickets ? `Terkirim ke ${data.tickets} station dapur.` : 'Tidak ada item baru untuk dapur.', 'ok');
      }
      if (act === 'serve') {
        const data = await api('POST', `visits/${state.token}/serve`);
        applyDetail(data);
        return toast(`${data.served} hidangan ditandai disajikan.`, 'ok');
      }
      if (act === 'bill') return confirmBill();
      if (act === 'close') {
        await api('POST', `visits/${state.token}/close`);
        toast('Meja ditutup.', 'ok');
        return showMap();
      }
      if (act === 'guests') return guestsDialog();
      if (act === 'move') return moveDialog();
      if (act === 'cook') return cookDialog();
    } catch (e) {
      fail(e);
    }
  });

  function confirmBill() {
    const d = state.detail;
    const unsent = d.lines.filter((l) => l.status === 'unsent' && l.kitchen).length;
    const root = dialog(`<h2>Minta bayar meja ${esc(d.visit.table.code)}?</h2>
      <p>Total <b class="mono">${rupiah(d.order.net_amount)}</b>. Kasir akan melihat meja ini di daftar Meja.</p>
      ${unsent ? `<p class="waiting">${unsent} item belum dikirim ke dapur.</p>` : ''}
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button><button class="btn amber" data-d="ok">Minta bayar</button></div>`);
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      try {
        applyDetail(await api('POST', `visits/${state.token}/bill`));
        closeDialog();
        toast('Kasir diberi tahu.', 'ok');
      } catch (e) {
        fail(e);
      }
    });
  }

  function guestsDialog() {
    const root = dialog(`<h2>Jumlah tamu</h2><div class="field">${stepper('d-g', state.detail.visit.guests)}</div>
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button><button class="btn primary" data-d="ok">Simpan</button></div>`);
    const g = bindStepper(root, 'd-g', 1, 50);
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      try {
        applyDetail(await api('POST', `visits/${state.token}/guests`, { guests: g() }));
        closeDialog();
      } catch (e) {
        fail(e);
      }
    });
  }

  async function moveDialog() {
    await loadTables();
    const root = dialog(`<h2>Pindah dari meja ${esc(state.detail.visit.table.code)}</h2><p class="muted">Pilih meja kosong.</p>
      <div class="pick-grid">${state.tables.map((t) => `<button type="button" data-to="${t.id}" ${t.status === 'empty' ? '' : 'disabled'}>${esc(t.code)}</button>`).join('')}</div>
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button></div>`);
    $('.pick-grid', root).addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-to]');
      if (!b) return;
      try {
        applyDetail(await api('POST', `visits/${state.token}/move`, { table_id: Number(b.dataset.to) }));
        closeDialog();
        toast('Meja dipindah.', 'ok');
      } catch (e) {
        fail(e);
      }
    });
  }

  // Daging yang dibeli tamu di meatshop: struk + Pack ID, dimasak dengan cooking fee.
  function cookDialog() {
    const methods = state.boot.cooking_methods.filter(Boolean);
    const root = dialog(`<h2>Masak daging tamu</h2><p class="muted">Untuk daging atau ikan yang sudah dibayar di kasir meatshop. Satu struk dan Pack ID hanya bisa dimasak sekali.</p>
      <div class="field"><label>Nomor struk</label><input id="c-receipt" autocomplete="off" placeholder="Scan atau ketik nomor struk"></div>
      <div class="field"><label>Pack ID</label><input id="c-pack" autocomplete="off" placeholder="Scan label kemasan"></div>
      ${methods.length ? `<div class="field"><label>Cara masak</label><div class="opts" id="c-method">${methods.map((m, i) => `<button type="button" data-v="${esc(m)}" class="${i === 0 ? 'on' : ''}">${esc(m)}</button>`).join('')}</div></div>` : ''}
      <div class="field"><label>Kematangan</label><div class="opts" id="c-done">${[['rare', 'Rare'], ['medium_rare', 'Medium rare'], ['medium', 'Medium'], ['well_done', 'Well done']].map(([k, l]) => `<button type="button" data-v="${k}" class="${k === 'medium' ? 'on' : ''}">${l}</button>`).join('')}</div></div>
      <div class="field"><label>Porsi</label>${stepper('c-portions', 1)}</div>
      <div class="field"><label>Saus / alergi</label><input id="c-sauce" placeholder="Saus (opsional)"><input id="c-allergy" placeholder="Alergi (opsional)"></div>
      <div class="d-acts"><button class="btn" data-d="cancel">Batal</button><button class="btn primary" data-d="ok">Minta masak</button></div>`);
    bindOpts(root, '#c-method');
    bindOpts(root, '#c-done');
    const portions = bindStepper(root, 'c-portions', 1, 20);
    $('#c-receipt', root).focus();
    $('[data-d="ok"]', root).addEventListener('click', async () => {
      try {
        applyDetail(await api('POST', `visits/${state.token}/cooking-from-receipt`, {
          receipt_no: $('#c-receipt', root).value, pack_code: $('#c-pack', root).value, portions: portions(),
          method: $('#c-method .on', root)?.dataset.v || null, doneness: $('#c-done .on', root)?.dataset.v || null,
          sauce: $('#c-sauce', root).value || null, allergy_note: $('#c-allergy', root).value || null,
        }));
        closeDialog();
        toast('Jasa masak ditambahkan. Kirim ke dapur bersama pesanan lain.', 'ok');
      } catch (e) {
        fail(e);
      }
    });
  }

  // ---- Mulai ------------------------------------------------------------------
  async function boot() {
    try {
      state.boot = await api('GET', 'bootstrap');
    } catch (e) {
      return fail(e);
    }
    $('#who').textContent = `${state.boot.outlet.code} · ${state.boot.outlet.name} · ${state.boot.user.name}`;
    await Promise.all([loadTables(), loadMenu()]);
    // Peta meja diperbarui tiap 5 detik; meja yang terbuka tiap 4 detik agar status dapur ikut.
    setInterval(() => { if (!$('#view-map').hidden && $('#dialog').hidden) loadTables(); }, 5000);
    setInterval(async () => {
      if (!state.token || !$('#dialog').hidden) return;
      try {
        applyDetail(await api('GET', 'visits/' + state.token));
        net(true);
      } catch (e) {
        if (e.code) fail(e); else net(false);
      }
    }, 4000);
  }
  boot();
})();
