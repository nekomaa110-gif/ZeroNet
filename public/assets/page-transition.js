(function () {
  'use strict';

  var KEY      = 'zn:page-dir';
  var MAX_AGE  = 15000;
  var DIRS     = { down: 1, up: 1, forward: 1, back: 1 };

  var root   = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var enter = readDir();
  if (!enter) {
    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (nav && nav.type === 'back_forward') enter = 'back';
  }
  if (enter && !reduce) {
    root.setAttribute('data-page-enter', enter);
    ready(armEnterCleanup);
  }

  function readDir() {
    var raw;
    try {
      raw = sessionStorage.getItem(KEY);
      sessionStorage.removeItem(KEY);
    } catch (_) { return null; }
    if (!raw) return null;
    var parsed;
    try { parsed = JSON.parse(raw); } catch (_) { return null; }
    if (!parsed || !DIRS[parsed.d]) return null;
    if (Date.now() - (parsed.t || 0) > MAX_AGE) return null;
    return parsed.d;
  }

  function storeDir(dir) {
    try { sessionStorage.setItem(KEY, JSON.stringify({ d: dir, t: Date.now() })); } catch (_) {}
  }

  function armEnterCleanup() {
    var page = document.querySelector('.page');
    if (!page) { root.removeAttribute('data-page-enter'); return; }
    var done = function (e) {
      if (e && e.target !== page) return;
      page.removeEventListener('animationend', done);
      root.removeAttribute('data-page-enter');
    };
    page.addEventListener('animationend', done);
    setTimeout(done, 600);
  }

  function leave(href, dir) {
    storeDir(dir);
    window.location.href = href;
  }

  function attrDir(el) {
    var holder = el.closest('[data-page-transition]');
    if (!holder) return null;
    var v = (holder.getAttribute('data-page-transition') || '').trim();
    return DIRS[v] ? v : 'none';
  }

  function sidebarDir(link) {
    var items = Array.prototype.slice.call(document.querySelectorAll('.sidebar a.nav-item, .sidebar a.nav-subitem'));
    if (!items.length) return null;

    var from = items.findIndex(function (a) { return a.classList.contains('active'); });
    var to   = items.indexOf(link);

    if (to < 0) {
      var path = new URL(link.href, location.href).pathname;
      to = items.findIndex(function (a) {
        return new URL(a.href, location.href).pathname === path;
      });
      if (to < 0) to = 0;
    }

    if (from < 0) return 'down';
    if (to === from) return null;
    return to > from ? 'down' : 'up';
  }

  function directionFor(link) {
    var forced = attrDir(link);
    if (forced) return forced === 'none' ? null : forced;

    if (link.closest('.sidebar')) return sidebarDir(link);
    if (link.rel === 'prev') return 'back';
    if (isAncestorPath(link)) return 'back';
    return 'forward';
  }

  function isAncestorPath(link) {
    var target = new URL(link.href, location.href).pathname.replace(/\/+$/, '');
    if (!target) return false;
    return location.pathname.replace(/\/+$/, '').indexOf(target + '/') === 0;
  }

  function eligible(link) {
    if (link.hasAttribute('download')) return false;
    if (link.target && link.target !== '_self') return false;
    if (link.closest('[data-no-page-transition]')) return false;

    var url;
    try { url = new URL(link.href, location.href); } catch (_) { return false; }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
    if (url.origin !== location.origin) return false;
    if (url.pathname === location.pathname && url.search === location.search) return false;
    return true;
  }

  function wire() {
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented) return;
      if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

      var link = e.target.closest && e.target.closest('a[href]');
      if (!link || !eligible(link)) return;

      var dir = directionFor(link);
      if (!dir) return;

      e.preventDefault();
      leave(link.href, dir);
    });

    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.hasAttribute('data-live-target')) return;
      if (form.hasAttribute('data-no-page-transition')) return;
      if (form.target && form.target !== '_self') return;
      if ((form.method || 'get').toLowerCase() === 'get') return;
      storeDir('forward');
    });
  }

  window.addEventListener('pageshow', function (e) {
    if (e.persisted) root.removeAttribute('data-page-enter');
  });

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(wire);
})();
