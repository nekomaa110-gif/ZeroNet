(function () {
  'use strict';

  const LS_KEY = 'zeronet-prefs-v2';
  const DEFAULTS = { theme: 'system', sidebar: 'full', density: 'cozy' };
  const THEMES = { light: 1, dark: 1, system: 1 };
  const SIDEBARS = { full: 1, icon: 1 };
  const DENSITIES = { compact: 1, cozy: 1, comfortable: 1 };
  const darkMq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function loadPrefs() {
    const p = { ...DEFAULTS };
    try {
      const raw = localStorage.getItem(LS_KEY);
      if (raw) Object.assign(p, JSON.parse(raw));
    } catch (e) { }
    if (!THEMES[p.theme]) p.theme = DEFAULTS.theme;
    if (!SIDEBARS[p.sidebar]) p.sidebar = DEFAULTS.sidebar;
    if (!DENSITIES[p.density]) p.density = DEFAULTS.density;
    return { theme: p.theme, sidebar: p.sidebar, density: p.density };
  }
  function savePrefs(p) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(p)); } catch (e) { }
  }
  function resolvedTheme(p) {
    if (p.theme === 'system') return darkMq && darkMq.matches ? 'dark' : 'light';
    return p.theme;
  }
  function applyPrefs(p) {
    const r = document.documentElement;
    const theme = resolvedTheme(p);
    r.dataset.theme = theme;
    r.dataset.themePref = p.theme;
    r.classList.toggle('dark', theme === 'dark');
    r.dataset.sidebar = p.sidebar;
    if (p.density === 'cozy') r.removeAttribute('data-density');
    else r.dataset.density = p.density;
  }

  const Prefs = loadPrefs();
  applyPrefs(Prefs);
  if (darkMq) {
    const onScheme = () => { if (Prefs.theme === 'system') { applyPrefs(Prefs); document.dispatchEvent(new CustomEvent('zeronet:prefs', { detail: { key: 'theme', value: 'system', prefs: Prefs } })); } };
    darkMq.addEventListener ? darkMq.addEventListener('change', onScheme) : darkMq.addListener(onScheme);
  }
  window.ZeroNet = window.ZeroNet || {};
  window.ZeroNet.prefs = Prefs;
  window.ZeroNet.setPref = function (key, value) {
    Prefs[key] = value;
    applyPrefs(Prefs);
    savePrefs(Prefs);
    document.dispatchEvent(new CustomEvent('zeronet:prefs', { detail: { key, value, prefs: Prefs } }));
  };
  window.ZeroNet.getPref = (k) => Prefs[k];
  window.ZeroNet.cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  function wireNetstat() {
    const box = document.querySelector('[data-netstat]');
    if (!box) return;
    const elR = box.querySelector('[data-ns-routers]');
    const elL = box.querySelector('[data-ns-led]');
    const elO = box.querySelector('[data-ns-online]');
    const render = (d) => {
      if (!d || d.gagal || !d.routers) {
        if (elL) elL.className = 'led';
        if (elR) elR.textContent = '?';
        return;
      }
      const list = Object.values(d.routers);
      const up = list.filter((r) => r && r.health && r.health.online).length;
      if (elR) elR.textContent = up + '/' + list.length;
      if (elL) elL.className = 'led ' + (up === list.length ? 'ok' : (up === 0 ? 'err' : 'warn'));
      box.title = up === list.length ? 'Semua router terhubung' : (list.length - up) + ' router tidak terhubung';
      if (elO && d.stats && d.stats.online_sessions !== undefined) elO.textContent = d.stats.online_sessions;
    };
    if (window.dashLive && typeof window.dashLive.on === 'function') { window.dashLive.on(render); return; }
    const url = box.dataset.url;
    if (!url) return;
    let timer = null;
    let busy = false;
    const poll = async () => {
      if (busy) return;
      busy = true;
      const ctl = new AbortController();
      const to = setTimeout(() => ctl.abort(), 8000);
      try {
        const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: ctl.signal });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        render(await r.json());
      } catch (e) { render(null); }
      finally { clearTimeout(to); busy = false; }
    };
    const start = () => { if (timer === null && !document.hidden) { poll(); timer = setInterval(poll, 60000); } };
    const stop = () => { clearInterval(timer); timer = null; };
    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
    start();
  }

  function wireRowLinks() {
    const go = (row, e) => {
      const href = row.getAttribute('data-href');
      if (!href) return;
      if (e && (e.ctrlKey || e.metaKey)) { window.open(href, '_blank'); return; }
      window.location.href = href;
    };
    document.addEventListener('click', (e) => {
      const row = e.target.closest && e.target.closest('tr[data-href]');
      if (!row || e.target.closest('a, button, input, select, textarea, label, form')) return;
      go(row, e);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const row = e.target.closest && e.target.closest('tr[data-href]');
      if (!row || e.target !== row) return;
      go(row, e);
    });
  }

  function wire() {
    wireNetstat();
    wireRowLinks();
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = (document.documentElement.dataset.theme === 'dark') ? 'light' : 'dark';
        window.ZeroNet.setPref('theme', next);
        updateThemeIcons();
      });
    });
    updateThemeIcons();

    document.querySelectorAll('[data-user-menu]').forEach((chip) => {
      chip.addEventListener('click', (e) => {
        e.stopPropagation();
        const menu = chip.nextElementSibling;
        if (!menu) return;
        const open = menu.classList.toggle('open');
        chip.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
    document.addEventListener('click', () => {
      document.querySelectorAll('.user-pop.open').forEach((m) => {
        m.classList.remove('open');
        m.previousElementSibling && m.previousElementSibling.setAttribute('aria-expanded', 'false');
      });
    });

    const sb       = document.querySelector('.sidebar');
    const overlay  = document.querySelector('[data-sidebar-overlay]');
    const isMobile = () => window.matchMedia('(max-width: 880px)').matches;
    const openSb   = () => {
      if (!sb) return;
      sb.classList.add('is-open');
      overlay && overlay.classList.add('is-open');
      if (isMobile()) document.body.classList.add('lock-scroll');
    };
    const closeSb  = () => {
      if (!sb) return;
      sb.classList.remove('is-open');
      overlay && overlay.classList.remove('is-open');
      document.body.classList.remove('lock-scroll');
    };
    const toggleSb = () => {
      if (!sb) return;
      sb.classList.contains('is-open') ? closeSb() : openSb();
    };

    document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); toggleSb(); });
    });
    overlay && overlay.addEventListener('click', closeSb);

    sb && sb.querySelectorAll('a.nav-item, a.nav-subitem').forEach((a) => {
      a.addEventListener('click', () => {
        if (isMobile()) closeSb();
      });
    });

    const groups   = sb ? Array.from(sb.querySelectorAll('[data-nav-group]')) : [];
    const iconMode = () => document.documentElement.dataset.sidebar === 'icon' && !isMobile();
    const syncState = () => {
      const icon = iconMode();
      groups.forEach((g) => {
        const btn = g.querySelector('[data-nav-group-btn]');
        const on  = g.classList.contains(icon ? 'pop' : 'open');
        btn && btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      });
      sb && sb.classList.toggle('has-pop', groups.some((g) => g.classList.contains('pop')));
    };
    const closePops = () => {
      if (!groups.some((g) => g.classList.contains('pop'))) return;
      groups.forEach((g) => g.classList.remove('pop'));
      syncState();
    };
    const placePop = (g, btn) => {
      const sub = g.querySelector('.nav-sub');
      const top = btn.getBoundingClientRect().top;
      const max = window.innerHeight - (sub ? sub.offsetHeight : 0) - 8;
      g.style.setProperty('--pop-top', Math.max(8, Math.min(top, max)) + 'px');
    };

    groups.forEach((g) => {
      const btn = g.querySelector('[data-nav-group-btn]');
      if (!btn) return;
      btn.addEventListener('click', () => {
        if (iconMode()) {
          const willPop = !g.classList.contains('pop');
          groups.forEach((o) => o.classList.remove('pop'));
          if (willPop) {
            g.classList.add('pop');
            placePop(g, btn);
          }
        } else {
          const willOpen = !g.classList.contains('open');
          groups.forEach((o) => o.classList.remove('open'));
          g.classList.toggle('open', willOpen);
        }
        syncState();
      });
    });
    syncState();

    document.addEventListener('click', (e) => {
      if (!e.target.closest || !e.target.closest('.nav-group.pop')) closePops();
    });
    sb && sb.querySelector('.nav') && sb.querySelector('.nav').addEventListener('scroll', closePops, { passive: true });
    document.addEventListener('zeronet:prefs', () => { closePops(); syncState(); });

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      closePops();
      if (sb && sb.classList.contains('is-open')) closeSb();
    });

    window.addEventListener('resize', () => {
      closePops();
      if (!isMobile()) {
        document.body.classList.remove('lock-scroll');
        overlay && overlay.classList.remove('is-open');
      }
    });
  }

  function updateThemeIcons() {
    const isDark = document.documentElement.dataset.theme === 'dark';
    document.querySelectorAll('[data-theme-icon]').forEach((el) => {
      el.innerHTML = isDark
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
