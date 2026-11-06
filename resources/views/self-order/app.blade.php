<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<title>Pesan di meja {{ $table->code }} · {{ $outletName }}</title>
<style>
  :root { --bg:#f3f5f4; --s:#fff; --ink:#15231c; --muted:#5d6d65; --line:#dbe3df; --green:#1f5a40; --green2:#174732; --amber:#e3a21a; --red:#b3352f; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.45 "Segoe UI","Noto Sans",Roboto,system-ui,sans-serif; padding-bottom:96px; }
  header { background:var(--green2); color:#fff; padding:16px; position:sticky; top:0; z-index:3; }
  header b { font-size:1.1rem; display:block; }
  header span { color:#cfe2d7; font-size:.85rem; }
  nav { display:flex; gap:8px; overflow-x:auto; padding:10px 16px; background:var(--s); border-bottom:1px solid var(--line); position:sticky; top:62px; z-index:2; }
  nav button { flex:none; border:1px solid var(--line); background:var(--s); border-radius:999px; padding:6px 14px; font:inherit; }
  nav button.on { background:var(--green); color:#fff; border-color:var(--green); }
  main { padding:12px 16px; max-width:640px; margin:0 auto; }
  h3 { margin:18px 0 8px; font-size:.8rem; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
  .item { display:flex; align-items:center; gap:12px; background:var(--s); border:1px solid var(--line); border-radius:12px; padding:12px; margin-bottom:8px; }
  .item div { flex:1; min-width:0; }
  .item small { color:var(--muted); display:block; }
  .qty { display:flex; align-items:center; gap:8px; }
  .qty button { width:34px; height:34px; border-radius:50%; border:1px solid var(--green); background:var(--s); color:var(--green); font-size:1.2rem; line-height:1; }
  .qty button.add { background:var(--green); color:#fff; }
  .qty b { min-width:18px; text-align:center; }
  .bar { position:fixed; left:0; right:0; bottom:0; background:var(--s); border-top:1px solid var(--line); padding:12px 16px calc(12px + env(safe-area-inset-bottom)); display:flex; gap:12px; align-items:center; z-index:4; }
  .bar div { flex:1; }
  .bar small { color:var(--muted); display:block; }
  .btn { border:0; border-radius:10px; background:var(--amber); color:#1d1300; font:600 1rem inherit; font-family:inherit; padding:12px 18px; }
  .btn:disabled { opacity:.5; }
  .sheet { position:fixed; inset:0; background:rgba(0,0,0,.4); display:none; align-items:flex-end; z-index:5; }
  .sheet.on { display:flex; }
  .sheet > div { background:var(--s); width:100%; max-width:640px; margin:0 auto; border-radius:16px 16px 0 0; padding:18px 16px 24px; max-height:85vh; overflow:auto; }
  label { display:block; margin:10px 0 4px; font-size:.85rem; color:var(--muted); }
  input, textarea { width:100%; font:inherit; padding:10px; border:1px solid var(--line); border-radius:8px; }
  .row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed var(--line); }
  .done { text-align:center; padding:40px 16px; }
  .err { color:var(--red); }
</style>
</head>
<body>
<header><b>{{ $outletName }}</b><span>Meja {{ $table->code }}{{ $table->area ? ' · '.$table->area : '' }} · Pesan mandiri</span></header>
<nav id="cats"></nav>
<main id="menu"><p class="muted">Memuat menu…</p></main>
<div class="bar"><div><small id="count">Belum ada pesanan</small><b id="total">Rp0</b></div><button class="btn" id="review" disabled>Lihat pesanan</button></div>
<div class="sheet" id="sheet"><div id="sheet-body"></div></div>
<script>
(() => {
  const token = @json($token);
  const base = @json(url('order/'.$token));
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const rp = (v) => 'Rp' + Math.round(Number(v)).toLocaleString('id-ID');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let menu = [], cart = {}, cat = null;

  const total = () => Object.entries(cart).reduce((s, [id, q]) => s + q * Number(menu.find((m) => m.id == id)?.price || 0), 0);
  const count = () => Object.values(cart).reduce((s, q) => s + q, 0);

  function render() {
    const cats = [...new Set(menu.map((m) => m.category || 'Lainnya'))];
    document.getElementById('cats').innerHTML = ['Semua', ...cats].map((c) => `<button data-cat="${esc(c)}" class="${(cat ?? 'Semua') === c ? 'on' : ''}">${esc(c)}</button>`).join('');
    const shown = cats.filter((c) => !cat || c === cat);
    document.getElementById('menu').innerHTML = shown.map((c) => `<h3>${esc(c)}</h3>` + menu.filter((m) => (m.category || 'Lainnya') === c).map((m) => `
      <div class="item"><div><b>${esc(m.name)}</b><small>${rp(m.price)}</small></div>
        <div class="qty">${cart[m.id] ? `<button data-minus="${m.id}">−</button><b>${cart[m.id]}</b>` : ''}<button class="add" data-plus="${m.id}">+</button></div></div>`).join('')).join('') || '<p>Menu belum tersedia.</p>';
    document.getElementById('total').textContent = rp(total());
    document.getElementById('count').textContent = count() ? count() + ' item dipilih' : 'Belum ada pesanan';
    document.getElementById('review').disabled = !count();
  }

  function review() {
    const items = Object.entries(cart).map(([id, q]) => ({ m: menu.find((x) => x.id == id), q }));
    document.getElementById('sheet-body').innerHTML = `<h2 style="margin-top:0">Pesanan meja {{ $table->code }}</h2>
      ${items.map(({ m, q }) => `<div class="row"><span>${q}× ${esc(m.name)}</span><span>${rp(q * m.price)}</span></div>`).join('')}
      <div class="row"><b>Perkiraan total</b><b>${rp(total())}</b></div>
      <label>Nama pemesan</label><input id="g-name" maxlength="60" autocomplete="name">
      <label>Jumlah tamu</label><input id="g-guests" type="number" min="1" max="50" value="2">
      <label>Catatan untuk dapur</label><textarea id="g-note" rows="2" maxlength="200" placeholder="Mis. tidak pedas"></textarea>
      <p class="muted" style="font-size:.85rem">Harga akhir, promo dan pajak dihitung kasir. Pesanan diproses setelah dikonfirmasi pelayan.</p>
      <p id="g-err" class="err"></p>
      <button class="btn" id="send" style="width:100%">Kirim pesanan</button>
      <button class="btn" id="back" style="width:100%;margin-top:8px;background:#e6f0eb">Kembali</button>`;
    document.getElementById('sheet').classList.add('on');
  }

  async function send() {
    const btn = document.getElementById('send');
    btn.disabled = true;
    try {
      const res = await fetch(base, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ items: Object.entries(cart).map(([id, q]) => ({ product_id: Number(id), quantity: q })),
          name: document.getElementById('g-name').value || null, guests: Number(document.getElementById('g-guests').value) || 1, note: document.getElementById('g-note').value || null }) });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Gagal mengirim pesanan.');
      cart = {};
      render();
      waiting(data.id);
    } catch (e) {
      document.getElementById('g-err').textContent = e.message;
      btn.disabled = false;
    }
  }

  function waiting(id) {
    const body = document.getElementById('sheet-body');
    body.innerHTML = '<div class="done"><h2>Pesanan terkirim</h2><p id="w-status">Menunggu konfirmasi pelayan…</p><button class="btn" id="back">Pesan lagi</button></div>';
    const poll = async () => {
      const r = await fetch(`${base}/status/${id}`, { headers: { Accept: 'application/json' } }).then((x) => x.json()).catch(() => null);
      if (!r) return setTimeout(poll, 8000);
      if (r.status === 'accepted') return (document.getElementById('w-status').textContent = 'Pesanan diterima dan sedang disiapkan dapur.');
      if (r.status === 'rejected') return (document.getElementById('w-status').textContent = 'Pesanan tidak dapat diproses: ' + (r.reject_reason || 'silakan hubungi pelayan.'));
      setTimeout(poll, 5000);
    };
    poll();
  }

  document.addEventListener('click', (ev) => {
    const t = ev.target;
    if (t.dataset.plus) { cart[t.dataset.plus] = (cart[t.dataset.plus] || 0) + 1; return render(); }
    if (t.dataset.minus) { cart[t.dataset.minus]--; if (cart[t.dataset.minus] <= 0) delete cart[t.dataset.minus]; return render(); }
    if (t.dataset.cat) { cat = t.dataset.cat === 'Semua' ? null : t.dataset.cat; return render(); }
    if (t.id === 'review') return review();
    if (t.id === 'send') return send();
    if (t.id === 'back' || t.id === 'sheet') return document.getElementById('sheet').classList.remove('on');
  });

  fetch(base + '/menu', { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => { menu = d; render(); })
    .catch(() => { document.getElementById('menu').innerHTML = '<p class="err">Menu gagal dimuat. Coba muat ulang.</p>'; });
})();
</script>
</body>
</html>
