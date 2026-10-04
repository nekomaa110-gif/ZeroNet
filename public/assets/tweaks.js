window.mountTweaks = function mountTweaks() {
  if (document.getElementById('tw-panel')) return;

  const groups = [
    { key: 'theme', label: 'Tema', opts: [['light', 'Terang'], ['dark', 'Gelap'], ['system', 'Ikut sistem']] },
    { key: 'density', label: 'Kepadatan tabel', opts: [['compact', 'Rapat'], ['cozy', 'Normal'], ['comfortable', 'Lega']] },
    { key: 'sidebar', label: 'Sidebar', opts: [['full', 'Lengkap'], ['icon', 'Ikon saja']] },
  ];

  const panel = document.createElement('div');
  panel.id = 'tw-panel';
  panel.className = 'tw-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Pengaturan tampilan');
  panel.innerHTML =
    '<div class="tw-head"><b>Tampilan</b>' +
    '<button type="button" class="icon-btn" id="tw-close" aria-label="Tutup">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
    '</button></div><div class="tw-body">' +
    groups.map((g) =>
      '<div class="tw-row"><div class="tw-row-l" id="tw-l-' + g.key + '">' + g.label + '</div>' +
      '<div class="tw-seg" role="radiogroup" aria-labelledby="tw-l-' + g.key + '" data-pref="' + g.key + '">' +
      g.opts.map((o) => '<button type="button" role="radio" data-val="' + o[0] + '">' + o[1] + '</button>').join('') +
      '</div></div>'
    ).join('') +
    '</div>';
  document.body.append(panel);

  let open = false;
  const setOpen = (v) => {
    open = v;
    panel.classList.toggle('open', v);
    if (v) {
      const on = panel.querySelector('button.on') || panel.querySelector('.tw-seg button');
      on && on.focus({ preventScroll: true });
    }
  };
  window.toggleTweaks = () => setOpen(!open);
  window.openTweaks = () => setOpen(true);
  window.closeTweaks = () => setOpen(false);

  panel.querySelector('#tw-close').addEventListener('click', () => setOpen(false));
  document.addEventListener('click', (e) => {
    if (!open || panel.contains(e.target) || e.target.closest('[data-tweaks-open]')) return;
    setOpen(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && open) setOpen(false);
  });

  function refresh() {
    panel.querySelectorAll('[data-pref]').forEach((group) => {
      const cur = window.ZeroNet.getPref(group.dataset.pref);
      group.querySelectorAll('button').forEach((b) => {
        const on = b.dataset.val === cur;
        b.classList.toggle('on', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
    });
  }
  panel.querySelectorAll('[data-pref]').forEach((group) => {
    group.querySelectorAll('button').forEach((b) => {
      b.addEventListener('click', () => {
        window.ZeroNet.setPref(group.dataset.pref, b.dataset.val);
        refresh();
      });
    });
  });
  refresh();
  document.addEventListener('zeronet:prefs', refresh);
};
