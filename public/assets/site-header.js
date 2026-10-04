(function () {
  var btn = document.querySelector('[data-nav-toggle]');
  var menu = document.getElementById('menu-mobile');
  if (!btn || !menu) return;

  function setOpen(open) {
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    setOpen(menu.hidden);
  });

  document.addEventListener('click', function (e) {
    if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) setOpen(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.hidden) { setOpen(false); btn.focus(); }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth >= 980 && !menu.hidden) setOpen(false);
  });
})();

(function () {
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-spy-section]'));
  var links = Array.prototype.slice.call(document.querySelectorAll('.site-header a[data-spy]'));
  if (!sections.length || !links.length) return;

  var header = document.querySelector('.site-header');
  var first = sections[0].id;
  var current = null;

  function setActive(id) {
    if (id === current) return;
    current = id;
    links.forEach(function (a) {
      if (a.getAttribute('data-spy') === id) {
        a.setAttribute('aria-current', id === first ? 'page' : 'location');
      } else {
        a.removeAttribute('aria-current');
      }
    });
  }

  function compute() {
    var line = (header ? header.offsetHeight : 64) + window.innerHeight * 0.3;
    var id = first;
    sections.forEach(function (s) {
      if (s.getBoundingClientRect().top <= line) id = s.id;
    });
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
      id = sections[sections.length - 1].id;
    }
    setActive(id);
  }

  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; compute(); });
  }, { passive: true });
  window.addEventListener('resize', compute);
  compute();
})();
