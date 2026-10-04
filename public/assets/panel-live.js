function pageAutoRefresh(seconds) {
  return {
    remaining: seconds,
    total: seconds,
    _timer: null,
    init() {
      this._timer = setInterval(() => {
        this.remaining--;
        if (this.remaining <= 0) location.reload();
      }, 1000);
    },
    reset() { this.remaining = this.total; },
    get pct() { return (this.remaining / this.total) * 100; }
  };
}

(function () {
  var toasts = document.querySelectorAll('[data-flash]');
  if (!toasts.length) return;

  var OUT_MS = 260;
  var HOLD_MS = 5000;

  function dismiss(el) {
    if (el.dataset.dismissed) return;
    el.dataset.dismissed = '1';
    el.classList.remove('is-in');
    setTimeout(function () { el.remove(); }, OUT_MS);
  }

  toasts.forEach(function (el, i) {
    el.addEventListener('click', function () { dismiss(el); });
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        setTimeout(function () { el.classList.add('is-in'); }, i * 90);
      });
    });
    setTimeout(function () { dismiss(el); }, HOLD_MS + i * 90);
  });
})();

(function () {
    const FOCUS_KEY = '__live_search_focus__';
    const SPINNER_SVG = '<svg class="spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="12" cy="12" r="10" opacity=".25"/><path d="M22 12a10 10 0 0 0-10-10" /></svg>';

    const last = sessionStorage.getItem(FOCUS_KEY);
    if (last) {
        sessionStorage.removeItem(FOCUS_KEY);
        const el = document.querySelector('[data-live-search][name="' + CSS.escape(last) + '"]');
        if (el) {
            el.focus();
            const len = el.value.length;
            try { el.setSelectionRange(len, len); } catch (_) {}
        }
    }

    function ensureSpinner(input) {
        const wrap = input.parentElement;
        if (!wrap) return null;
        let sp = wrap.querySelector('[data-live-spinner]');
        if (!sp) {
            sp = document.createElement('span');
            sp.setAttribute('data-live-spinner', '');
            sp.className = 'live-spinner';
            sp.innerHTML = SPINNER_SVG;
            wrap.appendChild(sp);
        }
        return sp;
    }
    function showSpinner(input) {
        const sp = ensureSpinner(input);
        if (sp) sp.classList.add('on');
        input.parentElement?.querySelectorAll('[data-live-clear], [data-live-reset]')
            .forEach(b => b.classList.add('hidden'));
    }
    function hideSpinner(input) {
        if (!input) return;
        const sp = input.parentElement?.querySelector('[data-live-spinner]');
        if (sp) sp.classList.remove('on');
        syncClearBtn(input);
        if (input.form) syncResetBtn(input.form);
    }

    function formUrl(form) {
        const data = new FormData(form);
        const params = new URLSearchParams();
        for (const [k, v] of data.entries()) {
            if (v !== '' && v !== null && k !== '_token' && k !== '_method') params.append(k, v);
        }
        const base = (form.action || window.location.href).split('?')[0];
        const qs = params.toString();
        return qs ? base + '?' + qs : base;
    }

    let currentCtrl = null;

    async function ajaxSwap(url, targetEl, sourceInput, ganti) {
        if (currentCtrl) currentCtrl.abort();
        currentCtrl = new AbortController();
        if (sourceInput) showSpinner(sourceInput);
        targetEl.style.opacity = '0.55';
        targetEl.style.pointerEvents = 'none';
        try {
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                signal: currentCtrl.signal,
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            targetEl.innerHTML = html;
            targetEl.querySelectorAll('template[data-live-oob]').forEach(function (tpl) {
                const lama = document.getElementById(tpl.getAttribute('data-live-oob'));
                if (lama) lama.replaceWith(tpl.content.cloneNode(true));
                tpl.remove();
            });
            history[ganti ? 'replaceState' : 'pushState']({ liveSearch: true }, '', url);
            targetEl.dispatchEvent(new CustomEvent('live-search:loaded', { bubbles: true }));
        } catch (e) {
            if (e.name !== 'AbortError') console.error('[live-search]', e);
        } finally {
            targetEl.style.opacity = '';
            targetEl.style.pointerEvents = '';
            if (sourceInput) hideSpinner(sourceInput);
        }
    }

    function triggerSubmit(form, sourceInput) {
        const targetSel = form.getAttribute('data-live-target');
        const targetEl  = targetSel ? document.querySelector(targetSel) : null;
        if (targetEl) {
            ajaxSwap(formUrl(form), targetEl, sourceInput);
        } else {
            if (sourceInput) showSpinner(sourceInput);
            if (sourceInput?.name) sessionStorage.setItem(FOCUS_KEY, sourceInput.name);
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    }

    function syncClearBtn(input) {
        const btn = input.parentElement?.querySelector('[data-live-clear]');
        if (btn) btn.classList.toggle('hidden', !input.value);
    }
    function syncResetBtn(form) {
        const btn = form.querySelector('[data-live-reset]');
        if (!btn) return;
        let hasFilter = false;
        form.querySelectorAll('input[name], select[name]').forEach(function (el) {
            if (el.type === 'hidden' || el.name === '_token' || el.name === '_method') return;
            if (el.value) hasFilter = true;
        });
        btn.classList.toggle('hidden', !hasFilter);
    }
    function syncAllResetBtns() { document.querySelectorAll('form').forEach(syncResetBtn); }

    document.querySelectorAll('[data-live-search]').forEach(function (input) {
        ensureSpinner(input);
        syncClearBtn(input);
        const delay = parseInt(input.dataset.liveSearch, 10) || 400;
        let timer = null;
        let lastVal = input.value;
        input.addEventListener('input', function () {
            syncClearBtn(input);
            if (input.form) syncResetBtn(input.form);
            if (input.value === lastVal) return;
            lastVal = input.value;
            clearTimeout(timer);
            if (input.form) showSpinner(input);
            timer = setTimeout(function () {
                if (!input.form) return;
                triggerSubmit(input.form, input);
            }, delay);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                clearTimeout(timer);
                if (input.form) {
                    e.preventDefault();
                    triggerSubmit(input.form, input);
                }
            }
        });
    });

    document.querySelectorAll('[data-live-clear]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const wrap = btn.parentElement;
            const input = wrap?.querySelector('[data-live-search]');
            if (!input) return;
            input.value = '';
            syncClearBtn(input);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (input.form) triggerSubmit(input.form, input);
            input.focus();
        });
    });

    document.querySelectorAll('[data-live-reset]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const form = btn.closest('form');
            if (!form) return;
            e.preventDefault();
            form.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.type === 'hidden' || el.name === '_token' || el.name === '_method') return;
                if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else el.value = '';
            });
            const search = form.querySelector('[data-live-search]');
            if (search) syncClearBtn(search);
            triggerSubmit(form, search);
        });
    });

    document.querySelectorAll('[data-live-submit]').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.form) {
                syncResetBtn(el.form);
                triggerSubmit(el.form, el.form.querySelector('[data-live-search]'));
            }
        });
    });

    syncAllResetBtns();

    document.querySelectorAll('form[data-live-target]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            triggerSubmit(form, form.querySelector('[data-live-search]'));
        });
    });

    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const targetEl = link.closest('[data-live-results]');
        if (!targetEl) return;
        if (link.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) return;
        const form = document.querySelector('form[data-live-target="#' + CSS.escape(targetEl.id) + '"]');
        if (!form) return;
        let linkUrl, formUrlObj;
        try {
            linkUrl = new URL(link.href, window.location.href);
            formUrlObj = new URL(form.action || window.location.href, window.location.href);
        } catch (_) { return; }
        if (linkUrl.origin !== formUrlObj.origin) return;
        if (linkUrl.pathname !== formUrlObj.pathname) return;
        e.preventDefault();
        ajaxSwap(link.href, targetEl, form.querySelector('[data-live-search]'));
    });

    window.addEventListener('popstate', function () {
        document.querySelectorAll('form[data-live-target]').forEach(function (form) {
            const targetEl = document.querySelector(form.getAttribute('data-live-target'));
            if (!targetEl) return;
            const params = new URL(window.location.href).searchParams;
            form.querySelectorAll('input[name], select[name]').forEach(function (el) {
                if (el.type === 'hidden') return;
                el.value = params.get(el.name) || '';
            });
            ajaxSwap(window.location.href, targetEl, form.querySelector('[data-live-search]'));
        });
    });

    document.addEventListener('live-search:muat-ulang', function (e) {
        const targetEl = e.target instanceof Element ? e.target.closest('[data-live-results]') : null;
        if (targetEl) ajaxSwap(window.location.href, targetEl, null, true);
    });
})();
