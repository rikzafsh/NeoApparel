/* Neo Apparel - skrip antarmuka ringan (tanpa dependensi) */
(function () {
  'use strict';
  window.tab = function (id) {
    ['login', 'register'].forEach(function (x) { var el = document.getElementById(x); if (el) el.style.display = x === id ? 'block' : 'none'; });
    document.querySelectorAll('.seg button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === id); });
  };
  // tinggi header dipakai elemen sticky lain (mis. tab halaman produk)
  var topnav = document.querySelector('.topnav');
  function setNavH() { if (topnav) document.documentElement.style.setProperty('--navh', topnav.offsetHeight + 'px'); }
  setNavH(); window.addEventListener('resize', setNavH);
  // dropdown menu: klik (sentuh/keyboard) membuka-menutup; hover ditangani CSS di layar besar
  var groups = [].slice.call(document.querySelectorAll('.navgroup'));
  function closeAll(except) { groups.forEach(function (g) { if (g !== except) { g.classList.remove('open'); var t = g.querySelector('.nav-trigger'); if (t) t.setAttribute('aria-expanded', 'false'); } }); }
  groups.forEach(function (g) {
    var t = g.querySelector('.nav-trigger'); if (!t) return;
    t.addEventListener('click', function (e) { e.stopPropagation(); var open = !g.classList.contains('open'); closeAll(g); g.classList.toggle('open', open); t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.navgroup')) closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(null); document.body.classList.remove('nav-open'); } });
  var navClose = document.getElementById('navClose'), scrim = document.getElementById('navScrim');
  if (navClose) navClose.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
  if (scrim) scrim.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
  var toggle = document.getElementById('navToggle');
  if (toggle) {
    toggle.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
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
