// Rasentra Kitchen: papan station/expeditor dan layar antrean pickup. Pembaruan diambil
// lewat polling ke server outlet (fallback bila WebSocket Reverb tidak tersedia);
// timer berjalan di klien dan disetel ulang dengan jam server pada setiap respons.
(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  let skew = 0; // selisih jam server - jam perangkat (ms)

  const clock = () => {
    const el = $('#clock');
    if (el) el.textContent = new Date(Date.now() + skew).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  };
  setInterval(clock, 1000);
  clock();

  async function api(method, path, body) {
    const res = await fetch('/kitchen/api/' + path, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '' },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 419) {
      location.href = '/pos/login';
      throw new Error('Sesi berakhir.');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Gagal.');
    if (data.server_time) skew = Date.parse(data.server_time) - Date.now();
    return data;
  }

  // ---- Bahasa layar per station (ID/EN), disimpan di perangkat --------------
  const DICT = {
    id: { active: 'Aktif', late: 'Terlambat', scan: 'Scan barcode tiket', empty: 'Belum ada tiket.', start: 'Mulai masak', ready: 'Siap', served: 'Disajikan',
      queued: 'Baru', acknowledged: 'Diterima', in_preparation: 'Dimasak', cancelled: 'Batal', complete: 'Lengkap, sajikan', waiting: 'Menunggu station lain',
      allergy: 'ALERGI', portions: 'porsi', dine_in: 'Dine-in', takeaway: 'Takeaway', pickup: 'Pickup', retail: 'Retail', pack: 'Pack', recall: 'Kembalikan ke Dimasak?', cancel_reason: 'Alasan batal:' },
    en: { active: 'Active', late: 'Late', scan: 'Scan ticket barcode', empty: 'No tickets yet.', start: 'Start cooking', ready: 'Ready', served: 'Served',
      queued: 'New', acknowledged: 'Seen', in_preparation: 'Cooking', cancelled: 'Void', complete: 'Complete, serve', waiting: 'Waiting for other stations',
      allergy: 'ALLERGY', portions: 'portions', dine_in: 'Dine-in', takeaway: 'Takeaway', pickup: 'Pickup', retail: 'Retail', pack: 'Pack', recall: 'Return to Cooking?', cancel_reason: 'Void reason:' },
  };
  const store = { get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* abaikan */ } } };

  const board = $('#board');
  if (board) initBoard();
  if (document.body.hasAttribute('data-queue')) initQueue();

  function initBoard() {
    const station = document.body.dataset.station;
    const expo = station === 'expo';
    let lang = store.get('rasentra.kds.lang.' + station) || 'id';
    let tickets = [];
    let loadedAt = Date.now();
    const t = (k) => DICT[lang][k] || k;

    const applyLang = () => {
      document.documentElement.lang = lang;
      $('#lang').textContent = lang === 'id' ? 'EN' : 'ID';
      document.querySelectorAll('[data-i18n]').forEach((el) => { el.textContent = t(el.dataset.i18n); });
      document.querySelectorAll('[data-i18n-ph]').forEach((el) => { el.placeholder = t(el.dataset.i18nPh); });
    };
    $('#lang').addEventListener('click', () => {
      lang = lang === 'id' ? 'en' : 'id';
      store.set('rasentra.kds.lang.' + station, lang);
      applyLang();
      render();
    });
    applyLang();

    const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

    function mods(i) {
      const ins = i.instructions || {};
      const parts = [];
      (ins.modifiers || []).forEach((m) => {
        if (typeof m === 'string') parts.push(m);
        else if (m && m.key !== 'cooking_fee') parts.push(m.label || m.key);
      });
      if (ins.pack_id || ins.raw_weight) parts.push(`${t('pack')} ${ins.raw_weight ? Number(ins.raw_weight).toLocaleString('id-ID') + ' kg' : ''}`);
      if (ins.portions) parts.push(`${ins.portions} ${t('portions')}`);
      ['method', 'doneness', 'sauce', 'course', 'note'].forEach((k) => { if (ins[k]) parts.push(ins[k]); });
      return parts.join(' · ');
    }

    function render() {
      const now = Date.now();
      const late = tickets.filter((x) => x.level === 'late').length;
      $('#count').textContent = tickets.length;
      $('#late').textContent = late;
      if (!tickets.length) {
        board.innerHTML = `<p class="empty">${esc(t('empty'))}</p>`;
        return;
      }
      board.innerHTML = tickets.map((tk) => {
        const elapsed = tk.elapsed + (now - loadedAt) / 1000;
        const level = expo ? tk.level : (tk.target && elapsed >= tk.target ? 'late' : tk.target && elapsed >= tk.target * 0.75 ? 'warn' : 'ok');
        const items = tk.items.map((i) => `
          <li class="${i.status}" data-item="${i.id}">
            <span class="q">${Number(i.quantity).toLocaleString('id-ID')}×</span>
            <span class="n">${esc(i.instructions?.product)}</span>
            <span class="st ${i.status}">${esc(t(i.status))}</span>
            ${expo && i.station ? `<span class="stn">${esc(i.station)}</span>` : ''}
            ${mods(i) ? `<span class="mods">${esc(mods(i))}</span>` : ''}
            ${i.instructions?.allergy ? `<span class="allergy">${esc(t('allergy'))}: ${esc(i.instructions.allergy)}</span>` : ''}
          </li>`).join('');
        const open = tk.items.filter((i) => ['queued', 'acknowledged', 'in_preparation'].includes(i.status));
        let action;
        if (expo) action = tk.complete ? `<button class="kbtn go" data-bump-order="${tk.order_id}">${esc(t('complete'))}</button>` : `<button class="kbtn" disabled>${esc(t('waiting'))}</button>`;
        else action = open.some((i) => i.status !== 'in_preparation') ? `<button class="kbtn" data-bump="${tk.id}">${esc(t('start'))}</button>` : `<button class="kbtn go" data-bump="${tk.id}">${esc(t('ready'))}</button>`;
        return `<article class="ticket ${level} ${tk.complete ? 'complete' : ''}">
          <div class="t-head" ${expo ? '' : `data-bump="${tk.id}"`}><b>${esc(tk.label)}</b><span class="t-time">${mmss(elapsed)}</span></div>
          <div class="t-meta"><span>${esc(tk.channel ? t(tk.channel) : '')}</span>${tk.bump_code ? `<span class="mono">#${esc(tk.bump_code)}</span>` : ''}${tk.sequence ? `<span>T${tk.sequence}</span>` : ''}</div>
          <ul class="t-items">${items}</ul>
          <div class="t-foot">${action}</div>
        </article>`;
      }).join('');
    }

    async function load() {
      try {
        const data = await api('GET', 'board/' + station);
        tickets = data.tickets;
        loadedAt = Date.now();
        $('#conn').textContent = 'LIVE';
        $('#conn').classList.remove('off');
        render();
      } catch (e) {
        $('#conn').textContent = 'OFFLINE';
        $('#conn').classList.add('off');
      }
    }

    function toast(msg, ok) {
      const el = $('#toast');
      el.textContent = msg;
      el.className = 'toast' + (ok ? ' ok' : '');
      el.hidden = false;
      clearTimeout(toast.timer);
      toast.timer = setTimeout(() => { el.hidden = true; }, 2500);
    }

    async function act(fn) {
      try {
        await fn();
        await load();
      } catch (e) {
        toast(e.message);
      }
    }

    board.addEventListener('click', (ev) => {
      const bump = ev.target.closest('[data-bump]');
      if (bump) return act(() => api('POST', `tickets/${bump.dataset.bump}/bump`));
      const order = ev.target.closest('[data-bump-order]');
      if (order) {
        const tk = tickets.find((x) => x.order_id === order.dataset.bumpOrder);
        return act(() => Promise.all(tk.items.filter((i) => i.status === 'ready').map((i) => api('POST', `items/${i.id}/bump`, { expo: true }))));
      }
      const li = ev.target.closest('[data-item]');
      if (!li) return;
      const status = li.className.trim();
      if (status === 'ready' && !expo) {
        if (confirm(t('recall'))) act(() => api('POST', `items/${li.dataset.item}/recall`));
        return;
      }
      if (status === 'cancelled') return;
      act(() => api('POST', `items/${li.dataset.item}/bump`, { expo }));
    });

    // Tekan lama pada item untuk membatalkan (dengan alasan); dicatat waste bila sudah dimasak.
    let pressTimer;
    board.addEventListener('pointerdown', (ev) => {
      const li = ev.target.closest('[data-item]');
      if (!li || li.classList.contains('cancelled')) return;
      pressTimer = setTimeout(() => {
        const reason = prompt(t('cancel_reason'));
        if (reason) act(() => api('POST', `items/${li.dataset.item}/cancel`, { reason }));
      }, 900);
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach((e) => board.addEventListener(e, () => clearTimeout(pressTimer)));

    // Pemindai barcode tiket cetak (keyboard wedge).
    const scan = $('#scan');
    scan.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Enter') return;
      const code = scan.value.trim();
      scan.value = '';
      if (code) act(async () => { await api('POST', 'scan', { code, expo }); toast('#' + code, true); });
    });
    document.addEventListener('pointerup', (ev) => { if (!ev.target.closest('input, button')) scan.focus({ preventScroll: true }); });

    load();
    setInterval(load, 4000);
    setInterval(render, 1000);
    scan.focus();
  }

  function initQueue() {
    let known = new Set();
    async function load() {
      try {
        const data = await api('GET', 'queue');
        $('#q-prep').innerHTML = data.preparing.map((q) => `<span>${q.no}</span>`).join('');
        $('#q-ready').innerHTML = data.ready.map((q) => `<span class="${q.served ? 'served' : known.has(q.no) ? '' : 'fresh'}">${q.no}</span>`).join('');
        const fresh = data.ready.filter((q) => !q.served && !known.has(q.no));
        if (fresh.length && known.size) chime();
        known = new Set(data.ready.map((q) => q.no));
      } catch (e) {
        /* layar antrean diam saja saat server tidak terjangkau */
      }
    }
    function chime() {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator();
        o.frequency.value = 880;
        o.connect(ctx.destination);
        o.start();
        o.stop(ctx.currentTime + 0.25);
      } catch (e) { /* tanpa suara */ }
    }
    load();
    setInterval(load, 3000);
  }
})();
