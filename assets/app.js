// Confirm dangerous actions: <button data-confirm="Are you sure?">
document.addEventListener('click', function (e) {
  const el = e.target.closest('[data-confirm]');
  if (el && !confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
});

// Prevent double submit
document.addEventListener('submit', function (e) {
  const f = e.target;
  if (f.dataset.submitted) { e.preventDefault(); return; }
  if (!f.hasAttribute('data-allow-resubmit')) {
    f.dataset.submitted = '1';
    setTimeout(() => { delete f.dataset.submitted; }, 4000);
  }
});

// Sections dropdown follows class dropdown: <select data-sections-for="classSelectId">
(function () {
  const map = window.SECTIONS || null;
  if (!map) return;
  document.querySelectorAll('select[data-sections-for]').forEach(function (sec) {
    const cls = document.getElementById(sec.dataset.sectionsFor);
    if (!cls) return;
    const keep = sec.dataset.selected || '';
    function fill() {
      const list = map[cls.value] || [];
      sec.innerHTML = '<option value="">' + (sec.dataset.empty || '— Section —') + '</option>';
      list.forEach(function (s) {
        const o = document.createElement('option');
        o.value = s.id; o.textContent = s.name;
        if (String(s.id) === String(keep)) o.selected = true;
        sec.appendChild(o);
      });
    }
    cls.addEventListener('change', function () { sec.dataset.selected = ''; fill(); });
    fill();
  });
})();

function fmtINR(n) {
  n = Math.round(n * 100) / 100;
  return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

// Collect fee page
(function () {
  const form = document.getElementById('collectForm');
  if (!form) return;
  const rows = Array.from(form.querySelectorAll('.pay-row'));
  const totalEl = document.getElementById('grandTotal');
  const feeEl = document.getElementById('feeTotal');
  const lateEl = document.getElementById('lateTotal');
  function recalc() {
    let fee = 0, late = 0;
    rows.forEach(function (r) {
      const cb = r.querySelector('.pick');
      const pay = r.querySelector('.pay');
      const lt = r.querySelector('.late');
      r.classList.toggle('checked', cb.checked);
      pay.disabled = !cb.checked; if (lt) lt.disabled = !cb.checked;
      if (cb.checked) { fee += parseFloat(pay.value || 0); if (lt) late += parseFloat(lt.value || 0); }
    });
    feeEl.textContent = fmtINR(fee); lateEl.textContent = fmtINR(late); totalEl.textContent = fmtINR(fee + late);
    document.getElementById('submitBtn').disabled = (fee + late) <= 0;
  }
  rows.forEach(function (r) {
    r.querySelector('.pick').addEventListener('change', function () {
      const pay = r.querySelector('.pay');
      if (this.checked && !parseFloat(pay.value)) pay.value = pay.dataset.balance;
      recalc();
    });
    r.querySelectorAll('input[type=number]').forEach(i => i.addEventListener('input', recalc));
  });
  // Pay a lump sum: fills oldest instalments first
  const lump = document.getElementById('lumpAmount');
  document.getElementById('lumpApply')?.addEventListener('click', function () {
    let left = parseFloat(lump.value || 0);
    rows.forEach(function (r) {
      const cb = r.querySelector('.pick'); const pay = r.querySelector('.pay'); const lt = r.querySelector('.late');
      const bal = parseFloat(pay.dataset.balance); const lf = lt ? parseFloat(lt.dataset.late || 0) : 0;
      if (left <= 0) { cb.checked = false; pay.value = bal; if (lt) lt.value = lf; return; }
      cb.checked = true;
      const lateTake = Math.min(lf, left); if (lt) lt.value = lateTake; left -= lateTake;
      const take = Math.min(bal, left); pay.value = Math.round(take * 100) / 100; left -= take;
    });
    recalc();
  });
  document.getElementById('selectDue')?.addEventListener('click', function () {
    rows.forEach(function (r) { r.querySelector('.pick').checked = r.dataset.due === '1'; r.querySelector('.pay').value = r.querySelector('.pay').dataset.balance; });
    recalc();
  });
  const mode = form.querySelector('[name=mode]');
  function refToggle() {
    const o = mode.selectedOptions[0];
    document.querySelectorAll('.ref-field').forEach(el => el.classList.toggle('d-none', o && o.dataset.cash === '1'));
    const ref = form.querySelector('[name=reference]'); if (ref) ref.required = !!(o && o.dataset.ref === '1');
  }
  mode.addEventListener('change', refToggle); refToggle();
  recalc();
})();
