(function () {
  'use strict';

  const POPUP = '.mdl-backdrop, .zc-backdrop, .drawer.open';
  const BEBAS = 'script, style, template, link, .flash-stack, .drawer-overlay';
  const root = document.documentElement;

  let dikunci = new Set();
  let aktif = null;
  let asal = null;
  let jadwal = null;

  function tampak(el) {
    if (el.hidden || !el.isConnected) return false;
    const cs = getComputedStyle(el);
    return cs.display !== 'none' && cs.visibility !== 'hidden';
  }

  function lapis(el) {
    return parseInt(getComputedStyle(el).zIndex, 10) || 0;
  }

  function teratas(daftar) {
    return daftar.reduce(function (a, b) {
      if (!a) return b;
      if (lapis(a) !== lapis(b)) return lapis(b) > lapis(a) ? b : a;
      return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? b : a;
    }, null);
  }

  function sasaran(popup) {
    const hasil = new Set();
    let node = popup;
    while (node.parentElement && node !== document.body) {
      for (const s of node.parentElement.children) {
        if (s !== node && !s.matches(BEBAS)) hasil.add(s);
      }
      node = node.parentElement;
    }
    return hasil;
  }

  function kunciScroll(nyala) {
    if (nyala) {
      root.style.setProperty('--zn-scrollbar', Math.max(0, window.innerWidth - root.clientWidth) + 'px');
      root.classList.add('zn-popup-open');
    } else {
      root.classList.remove('zn-popup-open');
      root.style.removeProperty('--zn-scrollbar');
    }
  }

  function hitung() {
    jadwal = null;
    const atas = teratas([...document.querySelectorAll(POPUP)].filter(tampak));
    const baru = atas ? sasaran(atas) : new Set();
    const tetap = new Set();

    dikunci.forEach(function (el) {
      if (baru.has(el)) tetap.add(el);
      else el.inert = false;
    });
    baru.forEach(function (el) {
      if (!tetap.has(el) && !el.inert) { el.inert = true; tetap.add(el); }
    });
    dikunci = tetap;

    if (atas && !aktif) {
      asal = document.activeElement;
      kunciScroll(true);
    } else if (!atas && aktif) {
      kunciScroll(false);
      if (asal && asal.isConnected && !asal.closest('[inert]') && typeof asal.focus === 'function') asal.focus({ preventScroll: true });
      asal = null;
    }
    aktif = atas;
  }

  function nanti() {
    if (!jadwal) jadwal = setTimeout(hitung, 0);
  }

  function mulai() {
    new MutationObserver(nanti).observe(document.body, {
      subtree: true, childList: true, attributes: true,
      attributeFilter: ['hidden', 'class', 'style'],
    });
    hitung();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mulai);
  else mulai();
})();
