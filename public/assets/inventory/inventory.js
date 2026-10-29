// Rasentra Inventory: satu kolom scan untuk semua tab. Scanner handheld bekerja sebagai
// keyboard dan mengirim Enter; hasil scan diarahkan ke tab yang sedang aktif.
(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const qty = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const isHead = ['outlet_head', 'admin'].includes(document.body.dataset.role);
  const canOpen = isHead || document.body.dataset.role === 'storekeeper';
  const state = { tab: 'receive', mode: 'accept', session: null, location: null, quarantine: null };
  const approvers = JSON.parse(document.body.dataset.approvers || '[]');
  const day = (v) => (v ? new Date(v + (v.length === 10 ? 'T00:00:00' : '')).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) : '-');

  async function api(method, path, body) {
    const res = await fetch('/inventory/api/' + path, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 419) { location.href = '/pos/login'; throw new Error('Sesi berakhir.'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Gagal.'));
      err.code = data.error || null;
      throw err;
    }
    return data;
  }

  function toast(msg, kind = '') {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast ' + kind;
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, kind === 'err' ? 4000 : 2000);
    if (kind === 'err' && navigator.vibrate) navigator.vibrate([80, 60, 80]);
  }

  // ---- Tab -----------------------------------------------------------------
  function showTab(name) {
    state.tab = name;
    document.querySelectorAll('.tab').forEach((t) => { t.hidden = t.id !== 'tab-' + name; });
    document.querySelectorAll('.tabs button').forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
    if (name === 'receive') loadExpected();
    if (name === 'count') loadSessions();
    if (name === 'quarantine') loadQuarantine();
    $('#scan').focus();
  }

  // ---- Penerimaan ----------------------------------------------------------
  async function loadExpected() {
    try {
      const list = await api('GET', 'receiving');
      $('#expected-count').textContent = list.length;
      $('#expected').innerHTML = list.map((p) => `<li><span>${esc(p.product)}<br><b>${esc(p.pack_code)}</b></span><span>${qty(p.net_weight)} ${esc(p.uom)}</span></li>`).join('')
        || '<li class="muted">Tidak ada kiriman yang ditunggu.</li>';
    } catch (e) { toast(e.message, 'err'); }
  }

  async function receive(code) {
    let reason = null;
    if (state.mode === 'reject') {
      reason = prompt('Alasan penolakan (mis. vakum bocor, suhu tinggi):');
      if (!reason) return;
    }
    try {
      const r = await api('POST', 'receiving', { code, action: state.mode, reason });
      $('#receive-result').innerHTML = `<div class="card ${r.status === 'received' ? 'ok' : 'err'}"><b>${esc(r.pack_code)}</b> · ${qty(r.net_weight)} kg<br>
        ${r.status === 'received' ? 'Diterima, masuk stok outlet.' : 'Ditolak, masuk karantina.'} Sisa ${r.remaining} pack.</div>`;
      toast(r.status === 'received' ? 'Diterima ' + r.pack_code : 'Ditolak ' + r.pack_code, r.status === 'received' ? 'ok' : '');
      loadExpected();
    } catch (e) {
      $('#receive-result').innerHTML = `<div class="card err">${esc(code)}: ${esc(e.message)}</div>`;
      toast(e.message, 'err');
    }
  }

  // ---- Opname --------------------------------------------------------------
  async function loadSessions() {
    try {
      const list = await api('GET', 'counts');
      const statusText = { counting: 'menghitung', recount: 'hitung ulang', review: 'menunggu persetujuan' };
      $('#count-sessions').innerHTML = list.map((s) => `<div class="card"><b>${esc(s.type)}${s.blind ? ' · blind' : ''}</b> · ${esc(statusText[s.status] || s.status)} · ronde ${s.current_round}
          <br><button class="btn" data-session="${s.id}" style="margin-top:8px">Buka sesi</button></div>`).join('')
        + (canOpen ? '<button class="btn" id="btn-new-count" style="width:100%;margin-top:8px">Buat sesi opname baru</button>' : '')
        || '<p class="muted">Belum ada sesi opname aktif.</p>';
    } catch (e) { toast(e.message, 'err'); }
  }

  async function openSession(id) {
    try {
      const p = await api('GET', 'counts/' + id);
      state.session = p.session;
      $('#count-active').hidden = false;
      $('#count-head').hidden = !isHead;
      $('#count-info').innerHTML = `<div class="card">Ronde ${p.session.current_round}${p.session.blind ? ' · saldo sistem disembunyikan' : ''} · ${p.total_lines} baris</div>`
        + (p.recount_products.length ? `<div class="card err"><b>Hitung ulang:</b> ${p.recount_products.map((x) => esc(x.name)).join(', ')}<br><span class="muted">Harus petugas berbeda dari hitungan pertama.</span></div>` : '');
      $('#count-lines').innerHTML = p.my_lines.map((l) => `<li><span>${esc(l.product)}${l.pack ? '<br><b>' + esc(l.pack) + '</b>' : ''}</span><span>${qty(l.quantity)} ${esc(l.uom)}</span></li>`).join('');
    } catch (e) { toast(e.message, 'err'); }
  }

  async function countScan(code) {
    // QR lokasi (ETL, GDG, ...) memilih lokasi, bukan baris hitung.
    const locBtn = document.querySelector(`[data-loc="${CSS.escape(code)}"]`);
    if (locBtn) return setLocation(code);
    if (!state.session) return toast('Buka sesi opname dulu.', 'err');
    if (!state.location) return toast('Scan QR lokasi dulu.', 'err');
    const q = $('#count-qty').value.trim();
    try {
      await api('POST', `counts/${state.session.id}/lines`, { code, location: state.location, quantity: q || null });
      $('#count-qty').value = '';
      toast('Tercatat ' + code, 'ok');
      openSession(state.session.id);
    } catch (e) { toast(e.message, 'err'); }
  }

  function setLocation(code) {
    state.location = code;
    $('#loc-current').textContent = code;
    document.querySelectorAll('[data-loc]').forEach((b) => b.classList.toggle('on', b.dataset.loc === code));
  }

  async function review() {
    try {
      const r = await api('POST', `counts/${state.session.id}/review`);
      const rows = r.lines.map((l) => `<tr class="${l.flagged ? 'flag' : ''}"><td>${esc(l.name)}${l.missing_packs.length ? `<br><small>Belum discan: ${l.missing_packs.map(esc).join(', ')}</small>` : ''}</td>
        <td>${qty(l.system)}</td><td>${qty(l.counted)}</td><td>${qty(l.variance)}</td></tr>`).join('');
      const next = r.session.status === 'recount'
        ? '<div class="card err">Selisih melewati ambang. Minta petugas lain menghitung ulang produk bertanda.</div>'
        : '<button class="btn" id="btn-approve" style="width:100%;margin-top:10px">Setujui dan posting penyesuaian</button>';
      $('#review').innerHTML = `<table><tr><th>Produk</th><th>Sistem</th><th>Hitung</th><th>Selisih</th></tr>${rows}</table>${next}`;
      openSession(state.session.id);
    } catch (e) { toast(e.message, 'err'); }
  }

  async function approve() {
    if (!confirm('Posting penyesuaian stok sebesar selisih? Tindakan ini tercatat di audit.')) return;
    try {
      await api('POST', `counts/${state.session.id}/approve`);
      toast('Opname disetujui dan diposting.', 'ok');
      state.session = null;
      $('#count-active').hidden = true;
      $('#review').innerHTML = '';
      loadSessions();
    } catch (e) { toast(e.message, 'err'); }
  }

  // ---- Cek stok ------------------------------------------------------------
  const MOVES = { opening_balance: 'Saldo awal', sale: 'Penjualan', return: 'Retur pelanggan', receipt: 'Terima kiriman', receipt_rejected: 'Kiriman ditolak', waste: 'Waste',
    count_adjustment: 'Selisih opname', quarantine_release: 'Lepas karantina', consumption: 'Pemakaian resep', production_issue: 'Bahan produksi prep', production_receipt: 'Hasil produksi prep' };
  async function check(code) {
    try {
      const r = await api('GET', 'lookup?code=' + encodeURIComponent(code));
      if (r.kind === 'pack') {
        const p = r.pack;
        $('#check-result').innerHTML = `<div class="card ${p.own_outlet ? 'ok' : 'err'}"><b>${esc(p.pack_code)}</b> · ${esc(p.product)}
          <dl><dt>Status</dt><dd>${esc(p.status)}</dd><dt>Berat</dt><dd>${qty(p.net_weight)} ${esc(p.uom_code)}</dd>
          <dt>Kedaluwarsa</dt><dd>${esc((p.expires_at || '').slice(0, 10))}</dd><dt>Outlet</dt><dd>${esc(p.outlet)}${p.own_outlet ? '' : ' (bukan outlet ini)'}</dd></dl></div>
          ${p.own_outlet ? `<div class="acts-row">
            ${['quarantine', 'damaged'].includes(p.status) ? `<button class="btn alt" data-act="release" data-code="${esc(p.pack_code)}">Lepas ke stok</button>` : ''}
            ${['received', 'available'].includes(p.status) ? `<button class="btn alt" data-act="hold" data-code="${esc(p.pack_code)}">Tahan</button>` : ''}
            ${['quarantine', 'damaged', 'received', 'available', 'hold'].includes(p.status) ? `<button class="btn danger" data-act="waste" data-code="${esc(p.pack_code)}">Buang</button>` : ''}
          </div>` : ''}
          <h3>Riwayat</h3><ul class="list">${r.events.map((e) => `<li><span>${esc(e.event)}${e.reason ? ' · ' + esc(e.reason) : ''}</span><span class="muted">${esc((e.occurred_at || '').slice(0, 16).replace('T', ' '))}</span></li>`).join('')}</ul>`;
      } else {
        $('#check-result').innerHTML = `<div class="card ok"><b>${esc(r.product.name)}</b> · ${esc(r.product.code)}
          <dl><dt>Tersedia</dt><dd>${qty(r.available)} ${esc(r.product.uom_code)}</dd><dt>Karantina</dt><dd>${qty(r.quarantine)}</dd>
          ${r.locations.map((l) => `<dt>${esc(l.location)}${l.state !== 'available' ? ' · ' + esc(l.state) : ''}</dt><dd>${qty(l.quantity)}</dd>`).join('')}</dl>
          ${!r.product.is_weighed ? `<div class="acts-row">${Number(r.quarantine) > 0 ? `<button class="btn alt" data-act="release" data-code="${esc(r.product.code)}" data-max="${r.quarantine}">Lepas karantina</button>` : ''}
            <button class="btn danger" data-act="waste" data-code="${esc(r.product.code)}" ${Number(r.quarantine) > 0 ? '' : 'data-from="available"'} data-max="${r.available}">Buang</button></div>` : ''}</div>
          <h3>Pergerakan terakhir</h3><ul class="list">${r.movements.map((m) => `<li><span>${esc(MOVES[m.movement_type] || m.movement_type)}${m.stock_state !== 'available' ? ' · ' + esc(m.stock_state) : ''}<br><span class="muted">${esc((m.occurred_at || '').slice(0, 16).replace('T', ' '))}</span></span><span>${Number(m.quantity) > 0 ? '+' : ''}${qty(m.quantity)}</span></li>`).join('') || '<li class="muted">Belum ada.</li>'}</ul>
          ${r.packs.length ? `<h3>Pack di outlet (${r.packs.length})</h3><ul class="list">${r.packs.map((p) => `<li><span><b>${esc(p.pack_code)}</b><br><span class="muted">${esc(p.status)} · exp ${esc((p.expires_at || '').slice(0, 10))}</span></span><span>${qty(p.net_weight)} kg</span></li>`).join('')}</ul>` : ''}`;
      }
    } catch (e) {
      $('#check-result').innerHTML = `<div class="card err">${esc(e.message)}</div>`;
    }
  }

  // ---- Karantina, waste, tahan ----------------------------------------------
  async function loadQuarantine() {
    try {
      const q = await api('GET', 'quarantine');
      state.quarantine = q;
      const n = q.packs.length + q.items.length + q.expiring.length;
      $('#q-count').hidden = !n;
      $('#q-count').textContent = n;
      const expired = (d) => d && d <= new Date().toISOString().slice(0, 10);
      const kinds = { waste: 'Waste', count: 'Opname', release: 'Lepas karantina' };
      $('#q-content').innerHTML = `
        <h3>Hampir kedaluwarsa <span class="badge">${q.expiring.length}</span></h3>
        <ul class="list">${q.expiring.map((p) => `<li><span>${esc(p.product)}<br><b>${esc(p.pack_code)}</b> · ${qty(p.net_weight)} ${esc(p.uom)}
            <span class="tag ${expired(p.expires_at) ? 'red' : ''}">${expired(p.expires_at) ? 'lewat' : 'exp'} ${day(p.expires_at)}</span>${p.status === 'hold' ? '<span class="tag">ditahan</span>' : ''}</span>
          <span class="acts">${p.status !== 'hold' ? `<button class="btn sm alt" data-act="hold" data-code="${esc(p.pack_code)}">Tahan</button>` : ''}
            <button class="btn sm danger" data-act="waste" data-code="${esc(p.pack_code)}">Buang</button></span></li>`).join('') || '<li class="muted">Tidak ada pack yang hampir kedaluwarsa.</li>'}</ul>
        <h3>Di karantina <span class="badge">${q.packs.length + q.items.length}</span></h3>
        <ul class="list">${q.packs.map((p) => `<li><span>${esc(p.product)}<br><b>${esc(p.pack_code)}</b> · ${qty(p.net_weight)} ${esc(p.uom)}
            <br><span class="muted">${esc(p.reason || p.status)}</span></span>
          <span class="acts"><button class="btn sm alt" data-act="release" data-code="${esc(p.pack_code)}">Ke stok</button>
            <button class="btn sm danger" data-act="waste" data-code="${esc(p.pack_code)}">Buang</button></span></li>`).join('')}
          ${q.items.map((i) => `<li><span>${esc(i.product)}<br><b>${esc(i.code)}</b> · ${qty(i.quantity)} ${esc(i.uom)}</span>
          <span class="acts"><button class="btn sm alt" data-act="release" data-code="${esc(i.code)}" data-max="${i.quantity}">Ke stok</button>
            <button class="btn sm danger" data-act="waste" data-code="${esc(i.code)}" data-from="quarantine" data-max="${i.quantity}">Buang</button></span></li>`).join('')}
          ${q.packs.length + q.items.length ? '' : '<li class="muted">Karantina kosong.</li>'}</ul>
        <h3>Dokumen terakhir</h3>
        <ul class="list">${q.recent.map((d) => `<li><span><b>${esc(d.doc_no)}</b> · ${esc(kinds[d.kind] || d.kind)}<br><span class="muted">${esc(d.lines)}${d.reason ? ' · ' + esc(d.reason) : ''}</span></span>
          <span class="muted">${esc(d.user || '')}</span></li>`).join('') || '<li class="muted">Belum ada.</li>'}</ul>`;
    } catch (e) { toast(e.message, 'err'); }
  }

  function closeDialog() { $('#dialog').hidden = true; $('#scan').focus(); }

  // Lembar keputusan: alasan, jumlah (untuk SKU), dan PIN kepala outlet bila petugas gudang.
  function decide(action, code, { pack = true, from = null, max = null, title = '' } = {}) {
    const titles = { waste: 'Buang (waste)', release: 'Lepas ke stok jual', hold: 'Tahan pack' };
    const reasons = {
      waste: ['Kedaluwarsa', 'Warna/bau berubah', 'Kemasan rusak', 'Vakum bocor', 'Suhu tidak sesuai'],
      release: ['Sudah diperiksa, layak jual', 'Kemasan diganti', 'Suhu kembali normal'],
      hold: ['Hampir kedaluwarsa', 'Perlu cek QC', 'Label tidak terbaca'],
    }[action];
    const needPin = action !== 'hold' && !isHead;
    const body = $('#dialog .dialog-body');
    body.innerHTML = `<h2>${titles[action]}</h2><p><b>${esc(code)}</b>${title ? ' · ' + esc(title) : ''}</p>
      ${!pack ? `<div class="field"><label>Jumlah${max ? ' (maks. ' + qty(max) + ')' : ''}</label><input id="d-qty" inputmode="decimal" value="${max && action === 'release' ? max : ''}"></div>` : ''}
      ${!pack && action === 'waste' && !from ? `<div class="field"><label>Diambil dari</label><div class="chips" id="d-from">
        <button type="button" data-from="available" class="on">Stok jual</button><button type="button" data-from="quarantine">Karantina</button></div></div>` : ''}
      <div class="field"><label>Alasan</label><div class="chips" id="d-reasons">${reasons.map((r) => `<button type="button" data-r="${esc(r)}">${esc(r)}</button>`).join('')}</div>
        <input id="d-reason" placeholder="Atau tulis alasan"></div>
      ${needPin ? `<div class="field"><label>Kepala outlet</label><select id="d-approver">${approvers.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></div>
        <div class="field"><label>PIN kepala outlet</label><input id="d-pin" type="password" inputmode="numeric" autocomplete="off"></div>` : ''}
      <div class="acts-row"><button class="btn alt" data-d="cancel">Batal</button><button class="btn ${action === 'waste' ? 'danger' : ''}" data-d="ok">${titles[action]}</button></div>`;
    $('#dialog').hidden = false;
    let source = from || 'available';
    body.onclick = async (ev) => {
      const t = ev.target.closest('button');
      if (!t) return;
      if (t.dataset.r) {
        body.querySelector('#d-reason').value = t.dataset.r;
        body.querySelectorAll('#d-reasons button').forEach((b) => b.classList.toggle('on', b === t));
        return;
      }
      if (t.dataset.from) {
        source = t.dataset.from;
        body.querySelectorAll('#d-from button').forEach((b) => b.classList.toggle('on', b === t));
        return;
      }
      if (t.dataset.d === 'cancel') return closeDialog();
      if (t.dataset.d !== 'ok') return;
      const reason = body.querySelector('#d-reason').value.trim();
      if (!reason) return toast('Pilih atau tulis alasan.', 'err');
      const payload = { action, code, reason, from: source };
      if (!pack) payload.quantity = body.querySelector('#d-qty').value.trim().replace(',', '.') || null;
      if (needPin) {
        const pin = body.querySelector('#d-pin').value;
        if (pin.length < 4) return toast('Masukkan PIN kepala outlet.', 'err');
        payload.approval = { approver_id: Number(body.querySelector('#d-approver').value), pin };
      }
      try {
        const r = await api('POST', 'adjustments', payload);
        closeDialog();
        toast(action === 'hold' ? `Pack ${r.pack_code} ditahan.` : `${r.doc_no} tercatat.`, 'ok');
        if (state.tab === 'quarantine') loadQuarantine();
        if (state.tab === 'check') check(code);
      } catch (e) { toast(e.message, 'err'); }
    };
    (body.querySelector('#d-qty') || body.querySelector('#d-reason')).focus();
  }

  // Scan di tab Karantina: pilih tindakan yang masuk akal untuk kode itu.
  async function quarantineScan(code) {
    try {
      const r = await api('GET', 'lookup?code=' + encodeURIComponent(code));
      if (r.kind === 'pack') {
        const p = r.pack;
        if (!p.own_outlet) return toast(`Pack ${code} bukan milik outlet ini.`, 'err');
        if (['quarantine', 'damaged'].includes(p.status)) return choose(code, p.product, ['release', 'waste'], { pack: true });
        if (['received', 'available'].includes(p.status)) return choose(code, p.product, ['hold', 'waste'], { pack: true });
        if (p.status === 'hold') return decide('waste', code, { title: p.product });
        return toast(`Pack ${code} berstatus ${p.status}.`, 'err');
      }
      if (r.product.is_weighed) return toast('Produk timbang diproses per Pack ID. Scan label pack-nya.', 'err');
      const acts = Number(r.quarantine) > 0 ? ['release', 'waste'] : ['waste'];
      return choose(code, r.product.name, acts, { pack: false, max: Number(r.quarantine) > 0 ? r.quarantine : r.available });
    } catch (e) { toast(e.message, 'err'); }
  }

  function choose(code, title, acts, opts) {
    if (acts.length === 1) return decide(acts[0], code, { ...opts, title });
    const labels = { waste: 'Buang (waste)', release: 'Lepas ke stok jual', hold: 'Tahan pack' };
    const body = $('#dialog .dialog-body');
    body.innerHTML = `<h2>${esc(title)}</h2><p><b>${esc(code)}</b></p>
      <div class="acts-row" style="flex-direction:column">${acts.map((a) => `<button class="btn block ${a === 'waste' ? 'danger' : 'alt'}" data-pick="${a}">${labels[a]}</button>`).join('')}
      <button class="btn block alt" data-pick="">Batal</button></div>`;
    $('#dialog').hidden = false;
    body.onclick = (ev) => {
      const t = ev.target.closest('[data-pick]');
      if (!t) return;
      if (!t.dataset.pick) return closeDialog();
      decide(t.dataset.pick, code, { ...opts, title, from: t.dataset.pick === 'waste' && opts.max && !opts.pack ? null : opts.from });
    };
  }

  // ---- Event ---------------------------------------------------------------
  function submitScan() {
    const code = $('#scan').value.trim();
    $('#scan').value = '';
    if (!code) return;
    if (state.tab === 'receive') receive(code);
    else if (state.tab === 'count') countScan(code);
    else if (state.tab === 'quarantine') quarantineScan(code);
    else check(code);
  }

  $('#scan').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submitScan(); } });
  $('#scan-go').addEventListener('click', submitScan);
  document.addEventListener('click', async (e) => {
    const tab = e.target.closest('[data-tab]');
    if (tab) return showTab(tab.dataset.tab);
    const mode = e.target.closest('[data-mode]');
    if (mode) {
      state.mode = mode.dataset.mode;
      document.querySelectorAll('[data-mode]').forEach((b) => b.classList.toggle('on', b === mode));
      return;
    }
    const act = e.target.closest('[data-act]');
    if (act) {
      const d = act.dataset;
      const isPack = !d.max;
      return decide(d.act, d.code, { pack: isPack, from: d.from || (d.act === 'release' ? 'quarantine' : null), max: d.max || null });
    }
    if (e.target === $('#dialog')) return closeDialog();
    const s = e.target.closest('[data-session]');
    if (s) return openSession(s.dataset.session);
    const loc = e.target.closest('[data-loc]');
    if (loc) return setLocation(loc.dataset.loc);
    if (e.target.id === 'btn-new-count') {
      try {
        const sess = await api('POST', 'counts', { type: 'cycle', blind: true });
        toast('Sesi opname dibuat. Cutoff saldo: sekarang.', 'ok');
        loadSessions();
        openSession(sess.id);
      } catch (err) { toast(err.message, 'err'); }
      return;
    }
    if (e.target.id === 'btn-review') return review();
    if (e.target.id === 'btn-approve') return approve();
  });

  showTab('receive');
  api('GET', 'quarantine').then((q) => {
    const n = q.packs.length + q.items.length + q.expiring.length;
    $('#q-count').hidden = !n;
    $('#q-count').textContent = n;
  }).catch(() => {});
})();
