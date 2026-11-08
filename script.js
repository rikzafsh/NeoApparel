/* Neo Apparel - skrip antarmuka ringan (tanpa dependensi) */
(function () {
  'use strict';
  window.tab = function (id) {
    ['login', 'register'].forEach(function (x) { var el = document.getElementById(x); if (el) el.style.display = x === id ? 'block' : 'none'; });
    document.querySelectorAll('.seg button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === id); });
  };
  var toggle = document.getElementById('navToggle');
  if (toggle) {
    toggle.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar') && !e.target.closest('#navToggle')) document.body.classList.remove('nav-open');
    });
  }
  // toast otomatis hilang
  document.querySelectorAll('.flash').forEach(function (f) { setTimeout(function () { f.style.opacity = '0'; f.style.transition = 'opacity .4s'; setTimeout(function () { f.remove(); }, 450); }, 6000); });
  // konfirmasi aksi berisiko
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  // cegah klik ganda pada tombol submit
  document.addEventListener('submit', function (e) {
    var b = e.target.querySelector('button[type=submit],button:not([type])');
    if (b && !e.defaultPrevented) setTimeout(function () { b.disabled = true; }, 0);
  });
})();
