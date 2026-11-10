(function () {
  'use strict';
  var search = document.getElementById('clfa-member-search');
  var list = document.getElementById('clfa-member-options');
  if (!search || !list) return;
  var rows = Array.from(list.querySelectorAll('label'));
  var selected = document.getElementById('clfa-member-selected');
  var empty = document.getElementById('clfa-member-empty');
  var picked = search.form.querySelector('input[name="invite_mode"][value="picked"]');
  function normalize(value) {
    return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }
  var entries = rows.map(function (row) {
    return { row: row, text: normalize(row.textContent), checkbox: row.querySelector('input') };
  });
  function update() {
    var terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
    var visible = 0;
    var checked = 0;
    entries.forEach(function (entry) {
      var matches = terms.every(function (term) { return entry.text.indexOf(term) !== -1; });
      // Hide only the label, never remove or disable its checked input.
      entry.row.style.display = matches ? 'block' : 'none';
      if (matches) visible++;
      if (entry.checkbox.checked) checked++;
    });
    selected.textContent = String(checked);
    empty.hidden = visible !== 0;
  }
  function choosePicked() { if (picked) picked.checked = true; }
  search.addEventListener('focus', choosePicked);
  search.addEventListener('input', function () { choosePicked(); update(); });
  search.addEventListener('keydown', function (event) {
    // Enter in the search box must not accidentally send invitations.
    if (event.key === 'Enter') event.preventDefault();
  });
  list.addEventListener('change', function () { choosePicked(); update(); });
  update();
})();
