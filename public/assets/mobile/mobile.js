// Rasentra Mobile: satu kolom scan untuk semua tab. Scanner handheld bekerja sebagai
// keyboard dan mengirim Enter; hasil scan diarahkan ke tab yang sedang aktif.
(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const qty = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const isHead = ['outlet_head', 'admin'].includes(document.body.dataset.role);
  const canOpen = isHead || document.body.dataset.role === 'storekeeper';
  const state = { tab: 'receive', mode: 'accept', session: null, location: null };

  async function api(method, path, body) {
    const res = await fetch('/mobile/api/' + path, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 419) { location.href = '/pos/login'; throw new Error('Sesi berakhir.'); }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Gagal.'));
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
  async function check(code) {
    try {
      const r = await api('GET', 'lookup?code=' + encodeURIComponent(code));
      if (r.kind === 'pack') {
        const p = r.pack;
        $('#check-result').innerHTML = `<div class="card ${p.own_outlet ? 'ok' : 'err'}"><b>${esc(p.pack_code)}</b> · ${esc(p.product)}
          <dl><dt>Status</dt><dd>${esc(p.status)}</dd><dt>Berat</dt><dd>${qty(p.net_weight)} ${esc(p.uom_code)}</dd>
          <dt>Kedaluwarsa</dt><dd>${esc((p.expires_at || '').slice(0, 10))}</dd><dt>Outlet</dt><dd>${esc(p.outlet)}${p.own_outlet ? '' : ' (bukan outlet ini)'}</dd></dl></div>
          <h3>Riwayat</h3><ul class="list">${r.events.map((e) => `<li><span>${esc(e.event)}${e.reason ? ' · ' + esc(e.reason) : ''}</span><span class="muted">${esc((e.occurred_at || '').slice(0, 16).replace('T', ' '))}</span></li>`).join('')}</ul>`;
      } else {
        $('#check-result').innerHTML = `<div class="card ok"><b>${esc(r.product.name)}</b> · ${esc(r.product.code)}
          <dl><dt>Tersedia</dt><dd>${qty(r.available)} ${esc(r.product.uom_code)}</dd><dt>Karantina</dt><dd>${qty(r.quarantine)}</dd></dl></div>
          ${r.packs.length ? `<h3>Pack di outlet (${r.packs.length})</h3><ul class="list">${r.packs.map((p) => `<li><span><b>${esc(p.pack_code)}</b><br><span class="muted">${esc(p.status)} · exp ${esc((p.expires_at || '').slice(0, 10))}</span></span><span>${qty(p.net_weight)} kg</span></li>`).join('')}</ul>` : ''}`;
      }
    } catch (e) {
      $('#check-result').innerHTML = `<div class="card err">${esc(e.message)}</div>`;
    }
  }

  // ---- Event ---------------------------------------------------------------
  function submitScan() {
    const code = $('#scan').value.trim();
    $('#scan').value = '';
    if (!code) return;
    if (state.tab === 'receive') receive(code);
    else if (state.tab === 'count') countScan(code);
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
})();
