// Rasentra Kitchen · Produksi: batch dari resep berstok (saus, kaldu) dan potong & porsi.
// Bahan keluar dan hasil masuk ke stok outlet sebagai satu dokumen produksi yang dikirim ke
// Rasentra Central lalu diposting ke SAP (Goods Issue bahan, Goods Receipt hasil).
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const REASONS = JSON.parse(document.body.dataset.reasons || '[]');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(',', '.')); return Number.isFinite(n) ? n : 0; };
  const qty = (v, d = 3) => num(v).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: d });
  const round = (v) => Math.round(v * 1000) / 1000;

  let data = { recipes: [], products: [], batches: [], tolerance: 2 };
  let current = null; // resep terpilih
  let mult = 1;
  let reason = null;
  let cutRows = [];
  let cutReason = null;

  async function api(method, body) {
    const res = await fetch('/kitchen/api/production', {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401 || res.status === 419) { location.href = '/pos/login'; throw new Error('Sesi berakhir.'); }
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || 'Gagal menyimpan.');
    return json;
  }

  let toastTimer;
  function toast(msg, ok = false) {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast' + (ok ? ' ok' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, ok ? 2600 : 4500);
  }

  async function load() {
    data = await api('GET');
    renderRecipes();
    renderSide();
    if (current) current = data.recipes.find((r) => r.version_id === current.version_id) || null;
    if (current) renderRecipeForm();
    if (!cutRows.length) resetCut();
    renderCut();
  }

  // ---- Mode ------------------------------------------------------------------
  $('#mode').addEventListener('click', (e) => {
    const b = e.target.closest('[data-mode]');
    if (!b) return;
    document.querySelectorAll('#mode button').forEach((x) => x.classList.toggle('on', x === b));
    $('#pane-recipe').hidden = b.dataset.mode !== 'recipe';
    $('#pane-cutting').hidden = b.dataset.mode !== 'cutting';
  });

  // ---- Dari resep --------------------------------------------------------------
  function renderRecipes() {
    $('#recipes').innerHTML = data.recipes.length ? data.recipes.map((r) => `
      <button type="button" class="recipe ${current?.version_id === r.version_id ? 'on' : ''}" data-v="${r.version_id}">
        <b>${esc(r.name)}</b>
        <span>Resep v${r.version} · hasil ${qty(r.yield)} ${esc(r.uom)} · ${r.lines.length} bahan</span>
        <span>Stok sekarang <b class="mono">${qty(r.stock)} ${esc(r.uom)}</b></span>
      </button>`).join('') : '<p class="muted">Belum ada resep berstok yang disetujui untuk outlet ini.</p>';
  }

  $('#recipes').addEventListener('click', (e) => {
    const b = e.target.closest('[data-v]');
    if (!b) return;
    current = data.recipes.find((r) => String(r.version_id) === b.dataset.v);
    mult = 1;
    reason = null;
    renderRecipes();
    renderRecipeForm();
  });

  function renderRecipeForm() {
    const r = current;
    const form = $('#recipe-form');
    form.hidden = false;
    const target = round(num(r.yield) * mult);
    form.innerHTML = `
      <h3>${esc(r.name)}</h3>
      <div class="prow">
        <div class="pfield"><span>Jumlah resep</span><div class="mult">${[1, 2, 3, 4].map((m) => `<button type="button" data-m="${m}" class="${m === mult ? 'on' : ''}">×${m}</button>`).join('')}</div></div>
        <label class="pfield"><span>Target hasil (${esc(r.uom)})</span><input id="r-target" inputmode="decimal" value="${target}"></label>
        <label class="pfield"><span>Hasil timbang aktual (${esc(r.uom)})</span><input id="r-output" inputmode="decimal" placeholder="0,000"></label>
      </div>
      <table class="ptable">
        <thead><tr><th>Bahan</th><th>Standar</th><th>Dipakai</th><th>Stok</th></tr></thead>
        <tbody id="r-lines"></tbody>
      </table>
      <div class="balance" id="r-balance"></div>
      <div id="r-reason"></div>
      <label class="pfield wide"><span>Catatan (opsional)</span><input id="r-notes" placeholder="Mis. batch pagi untuk shift makan siang"></label>
      <button class="kbtn go" id="r-save">Simpan produksi</button>`;
    renderLines();
    renderRecipeBalance();
  }

  function scale() {
    return num($('#r-target').value) / num(current.yield);
  }

  function renderLines() {
    const f = scale();
    $('#r-lines').innerHTML = current.lines.map((l) => {
      const std = round(num(l.quantity) * f);
      const low = num(l.stock) < std;
      return `<tr data-p="${l.product_id}" data-std="${std}">
        <td>${esc(l.name)}</td>
        <td class="mono">${qty(std)} ${esc(l.uom)}</td>
        <td><input inputmode="decimal" value="${std}"> ${esc(l.uom)}</td>
        <td class="mono ${low ? 'low' : ''}">${qty(l.stock)}</td></tr>`;
    }).join('');
  }

  function renderRecipeBalance() {
    const target = num($('#r-target').value);
    const out = num($('#r-output').value);
    const loss = round(target - out);
    const pct = target > 0 ? (loss / target) * 100 : 0;
    const needReason = out > 0 && pct > data.tolerance;
    $('#r-balance').innerHTML = `
      <div><span>Target</span><b>${qty(target)}</b></div>
      <div><span>Hasil aktual</span><b>${out ? qty(out) : '-'}</b></div>
      <div class="${needReason ? 'bad' : ''}"><span>Susut</span><b>${out ? `${qty(Math.max(loss, 0))} (${qty(Math.max(pct, 0), 1)}%)` : '-'}</b></div>`;
    $('#r-reason').innerHTML = needReason ? reasonButtons(reason, 'r') : '';
    if (!needReason) reason = null;
  }

  function reasonButtons(selected, key) {
    return `<div class="pfield"><span>Alasan susut (di atas ${data.tolerance}%)</span><div class="reasons" data-reasons="${key}">
      ${REASONS.map((t) => `<button type="button" class="${t === selected ? 'on' : ''}">${esc(t)}</button>`).join('')}</div></div>`;
  }

  $('#recipe-form').addEventListener('click', async (e) => {
    const m = e.target.closest('[data-m]');
    if (m) {
      mult = Number(m.dataset.m);
      $('#r-target').value = round(num(current.yield) * mult);
      document.querySelectorAll('.mult button').forEach((x) => x.classList.toggle('on', x === m));
      renderLines();
      renderRecipeBalance();
      return;
    }
    const rb = e.target.closest('[data-reasons="r"] button');
    if (rb) { reason = rb.textContent; renderRecipeBalance(); return; }
    if (e.target.id === 'r-save') saveRecipe(e.target);
  });

  $('#recipe-form').addEventListener('input', (e) => {
    if (e.target.id === 'r-target') {
      document.querySelectorAll('.mult button').forEach((x) => x.classList.remove('on'));
      renderLines();
      renderRecipeBalance();
    }
    if (e.target.id === 'r-output') renderRecipeBalance();
    if (e.target.closest('#r-lines')) {
      const tr = e.target.closest('tr');
      tr.querySelector('input').classList.toggle('diff', Math.abs(num(e.target.value) - num(tr.dataset.std)) > 0.0005);
    }
  });

  async function saveRecipe(btn) {
    const output = num($('#r-output').value);
    if (!output) return toast('Timbang hasil produksi dan isi hasil aktual.');
    btn.disabled = true;
    try {
      const res = await api('POST', {
        mode: 'recipe', version_id: current.version_id, quantity: num($('#r-target').value), output,
        inputs: [...document.querySelectorAll('#r-lines tr')].map((tr) => ({ product_id: Number(tr.dataset.p), quantity: num(tr.querySelector('input').value) })),
        loss_reason: reason, notes: $('#r-notes').value || null,
      });
      toast(`Produksi tersimpan · ${res.doc_no}. Stok ${current.name} bertambah.`, true);
      reason = null;
      await load();
      $('#r-output').value = '';
      renderRecipeBalance();
    } catch (err) {
      toast(err.message);
    } finally {
      btn.disabled = false;
    }
  }

  // ---- Potong & porsi --------------------------------------------------------
  const kgProducts = () => data.products.filter((p) => p.uom === 'KG');
  const options = (selected, filter = () => true) => kgProducts().filter(filter).map((p) =>
    `<option value="${p.id}" ${String(p.id) === String(selected) ? 'selected' : ''}>${esc(p.name)} · stok ${qty(p.stock)}</option>`).join('');

  function resetCut() {
    const raw = kgProducts().find((p) => /utuh/i.test(p.name)) || kgProducts()[0];
    const portion = kgProducts().find((p) => /sirloin portion/i.test(p.name));
    const trim = kgProducts().find((p) => /trimming/i.test(p.name));
    cutRows = { input: { product_id: raw?.id, quantity: '' }, outputs: [{ product_id: portion?.id, quantity: '' }, { product_id: trim?.id, quantity: '' }] };
    cutReason = null;
  }

  function renderCut() {
    if (!data.products.length) return;
    const r = cutRows;
    $('#cut-form').innerHTML = `
      <h3>Bahan masuk</h3>
      <div class="prow">
        <label class="pfield wide" style="flex:1"><span>Bahan dipotong</span><select id="c-in">${options(r.input.product_id)}</select></label>
        <label class="pfield"><span>Berat (KG)</span><input id="c-in-qty" inputmode="decimal" value="${esc(r.input.quantity)}" placeholder="0,000"></label>
      </div>
      <h3>Hasil</h3>
      <table class="ptable"><thead><tr><th>Produk hasil</th><th>Berat (KG)</th><th></th></tr></thead>
        <tbody id="c-outs">${r.outputs.map((o, i) => `<tr data-i="${i}">
          <td><select data-f="product">${options(o.product_id)}</select></td>
          <td><input data-f="qty" inputmode="decimal" value="${esc(o.quantity)}" placeholder="0,000"></td>
          <td>${r.outputs.length > 1 ? `<button type="button" class="kbtn ghost small" data-del="${i}">Hapus</button>` : ''}</td></tr>`).join('')}</tbody></table>
      <div><button type="button" class="kbtn ghost small" id="c-add">+ Tambah hasil</button></div>
      <div class="balance" id="c-balance"></div>
      <div id="c-reason"></div>
      <label class="pfield wide"><span>Catatan (opsional)</span><input id="c-notes" placeholder="Mis. sirloin utuh dari kiriman pagi"></label>
      <button class="kbtn go" id="c-save">Simpan potong &amp; porsi</button>`;
    renderCutBalance();
  }

  function renderCutBalance() {
    const inQty = num(cutRows.input.quantity);
    const outQty = cutRows.outputs.reduce((s, o) => s + num(o.quantity), 0);
    const loss = round(inQty - outQty);
    const pct = inQty > 0 ? (loss / inQty) * 100 : 0;
    const needReason = inQty > 0 && outQty > 0 && pct > data.tolerance;
    $('#c-balance').innerHTML = `
      <div><span>Bahan masuk</span><b>${inQty ? qty(inQty) : '-'}</b></div>
      <div><span>Total hasil</span><b>${outQty ? qty(outQty) : '-'}</b></div>
      <div class="${needReason || loss < 0 ? 'bad' : ''}"><span>Susut</span><b>${inQty && outQty ? `${qty(loss)} (${qty(pct, 1)}%)` : '-'}</b></div>`;
    $('#c-reason').innerHTML = loss < 0 ? '<p class="low">Hasil melebihi bahan masuk. Periksa timbangan.</p>' : (needReason ? reasonButtons(cutReason, 'c') : '');
    if (!needReason) cutReason = null;
  }

  $('#cut-form').addEventListener('input', (e) => {
    if (e.target.id === 'c-in-qty') cutRows.input.quantity = e.target.value;
    const tr = e.target.closest('tr[data-i]');
    if (tr && e.target.dataset.f === 'qty') cutRows.outputs[tr.dataset.i].quantity = e.target.value;
    renderCutBalance();
  });

  $('#cut-form').addEventListener('change', (e) => {
    if (e.target.id === 'c-in') cutRows.input.product_id = e.target.value;
    const tr = e.target.closest('tr[data-i]');
    if (tr && e.target.dataset.f === 'product') cutRows.outputs[tr.dataset.i].product_id = e.target.value;
  });

  $('#cut-form').addEventListener('click', async (e) => {
    if (e.target.id === 'c-add') { cutRows.outputs.push({ product_id: kgProducts()[0]?.id, quantity: '' }); renderCut(); return; }
    const del = e.target.closest('[data-del]');
    if (del) { cutRows.outputs.splice(Number(del.dataset.del), 1); renderCut(); return; }
    const rb = e.target.closest('[data-reasons="c"] button');
    if (rb) { cutReason = rb.textContent; renderCutBalance(); return; }
    if (e.target.id !== 'c-save') return;
    const btn = e.target;
    btn.disabled = true;
    try {
      const res = await api('POST', {
        mode: 'cutting',
        inputs: [{ product_id: Number(cutRows.input.product_id), quantity: num(cutRows.input.quantity) }],
        outputs: cutRows.outputs.filter((o) => num(o.quantity) > 0).map((o) => ({ product_id: Number(o.product_id), quantity: num(o.quantity) })),
        loss_reason: cutReason, notes: $('#c-notes').value || null,
      });
      toast(`Potong & porsi tersimpan · ${res.doc_no}.`, true);
      cutRows.input.quantity = '';
      cutRows.outputs.forEach((o) => { o.quantity = ''; });
      cutReason = null;
      await load();
    } catch (err) {
      toast(err.message);
    } finally {
      btn.disabled = false;
    }
  });

  // ---- Samping: stok prep dan riwayat ------------------------------------------
  function renderSide() {
    const prep = data.products.filter((p) => p.type === 'prep');
    $('#prep-stock').innerHTML = prep.length
      ? prep.map((p) => `<div><span>${esc(p.name)}</span><b class="${num(p.stock) < 0 ? 'low' : ''}">${qty(p.stock)} ${esc(p.uom)}</b></div>`).join('')
      : '<div class="muted">Belum ada produk prep.</div>';
    $('#history').innerHTML = data.batches.length ? data.batches.map((b) => `
      <article class="batch">
        <header><b>${esc(b.title)}</b><span class="mono m">${new Date(b.at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span></header>
        <span class="m mono">${esc(b.doc_no || '')} · ${esc(b.user || '')}</span>
        <span class="out">Hasil: ${b.outputs.map((o) => `${esc(o.name)} ${qty(o.quantity)} ${esc(o.uom)}`).join(', ')}${b.expected ? ` <span class="m">(target ${qty(b.expected)})</span>` : ''}</span>
        <span class="m">Bahan: ${b.inputs.map((o) => `${esc(o.name)} ${qty(o.quantity)} ${esc(o.uom)}`).join(', ')}</span>
        ${num(b.loss) > 0 ? `<span class="loss">Susut ${qty(b.loss)}${b.loss_reason ? ' · ' + esc(b.loss_reason) : ''}</span>` : ''}
      </article>`).join('') : '<p class="muted">Belum ada produksi hari ini.</p>';
  }

  load().catch((e) => toast(e.message));
})();
