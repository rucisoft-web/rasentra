// Rasentra Core: interaksi kecil tanpa framework (formulir dinamis, konfirmasi).
document.addEventListener('click', (e) => {
  const confirmEl = e.target.closest('[data-confirm]');
  if (confirmEl && !window.confirm(confirmEl.dataset.confirm)) {
    e.preventDefault();
    return;
  }

  // Tambah baris dari <template data-row-template="..."> ke tbody yang sama namanya.
  const add = e.target.closest('[data-add-row]');
  if (add) {
    e.preventDefault();
    const name = add.dataset.addRow;
    const tpl = document.querySelector(`template[data-row-template="${name}"]`);
    const body = document.querySelector(`[data-rows="${name}"]`);
    if (tpl && body) {
      const index = Date.now();
      body.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__i__', index));
    }
  }

  const remove = e.target.closest('[data-remove-row]');
  if (remove) {
    e.preventDefault();
    remove.closest('tr')?.remove();
  }
});

// Tampilkan isian aturan sesuai tipe promo yang dipilih (Promotion Designer).
function syncPromoType() {
  const select = document.querySelector('[data-promo-type]');
  if (!select) return;
  const type = select.value;
  document.querySelectorAll('[data-for-types]').forEach((el) => {
    const types = el.dataset.forTypes.split(' ');
    el.classList.toggle('hide', !types.includes(type));
  });
  const scope = document.querySelector('[name="scope"]');
  if (scope) {
    if (type === 'amount') scope.value = 'basket';
    else if (type !== 'percent') scope.value = 'item';
  }
}
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-promo-type]')) syncPromoType();
  if (e.target.matches('[data-target-mode]')) {
    const mode = e.target.value;
    document.querySelectorAll('[data-target-panel]').forEach((el) => el.classList.toggle('hide', el.dataset.targetPanel !== mode));
  }
});
document.addEventListener('DOMContentLoaded', syncPromoType);
