(function () {
  'use strict';

  const css = `
    .zc-backdrop {
      position: fixed; inset: 0; z-index: 9998;
      display: flex; align-items: center; justify-content: center; padding: 16px;
      background: var(--scrim, rgba(10,14,12,.52));
      opacity: 0; transition: opacity .15s ease;
    }
    .zc-backdrop.zc-open { opacity: 1; }
    .zc-modal {
      width: 100%; max-width: 440px; overflow: hidden;
      background: var(--surface, #fff); color: var(--ink, #141815);
      border: 1px solid var(--line, #D3D9D5); border-radius: var(--r-xl, 8px);
      box-shadow: var(--shadow-pop, 0 16px 40px -12px rgba(10,14,12,.32));
      transform: translateY(8px); opacity: 0;
      transition: transform .18s cubic-bezier(.2,0,0,1), opacity .18s ease;
    }
    .zc-backdrop.zc-open .zc-modal { transform: none; opacity: 1; }
    .zc-head { display: flex; align-items: flex-start; gap: 14px; padding: 20px 22px 8px; }
    .zc-icon { flex: 0 0 auto; width: 38px; height: 38px; display: grid; place-items: center; border-radius: var(--r-md, 4px); }
    .zc-icon.zc-danger  { background: var(--err-tint); color: var(--err); }
    .zc-icon.zc-warning { background: var(--warn-tint); color: var(--warn); }
    .zc-icon.zc-info    { background: var(--brand-tint); color: var(--brand-ink); }
    .zc-icon svg { width: 20px; height: 20px; }
    .zc-title { margin: 0; padding-top: 7px; font-size: 16px; font-weight: 600; line-height: 1.35; }
    .zc-body { padding: 4px 22px 20px 74px; color: var(--ink-2, #434B46); font-size: 14px; line-height: 1.5; }
    .zc-body p { margin: 0; white-space: pre-wrap; }
    .zc-pwd-field { margin-top: 14px; }
    .zc-pwd-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--ink); }
    .zc-pwd-input {
      width: 100%; min-height: 40px; padding: 8px 11px;
      background: var(--surface); color: var(--ink);
      border: 1px solid var(--field-line, #7B847E); border-radius: var(--r-md, 4px);
      font: inherit; font-size: 14px;
    }
    .zc-pwd-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 22%, transparent); }
    .zc-pwd-input.zc-err { border-color: var(--err); box-shadow: 0 0 0 3px color-mix(in srgb, var(--err) 20%, transparent); }
    .zc-pwd-err { display: none; margin-top: 6px; color: var(--err); font-size: 13px; line-height: 1.4; }
    .zc-pwd-err.zc-show { display: block; }
    .zc-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; background: var(--surface-2); border-top: 1px solid var(--line); }
    .zc-btn {
      min-height: 36px; padding: 7px 16px;
      border-radius: var(--r-md, 4px); border: 1px solid transparent;
      font: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer;
    }
    .zc-btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    .zc-btn-cancel { background: var(--surface); border-color: var(--line-strong); color: var(--ink); }
    .zc-btn-cancel:hover { background: var(--sunken); }
    .zc-btn-confirm { color: #fff; }
    .zc-btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
    .zc-btn-confirm.zc-danger  { background: var(--err); }
    .zc-btn-confirm.zc-warning { background: var(--warn); }
    .zc-btn-confirm.zc-info    { background: var(--brand); color: var(--on-brand); }
    .zc-btn-confirm:hover { filter: brightness(.92); }
    [data-theme="dark"] .zc-btn-confirm.zc-danger, [data-theme="dark"] .zc-btn-confirm.zc-warning { color: var(--ink-inverse); }
    @media (max-width: 560px) {
      .zc-backdrop { align-items: flex-end; padding: 0; }
      .zc-modal { max-width: none; border-radius: var(--r-xl, 8px) var(--r-xl, 8px) 0 0; }
      .zc-head { padding: 18px 18px 6px; }
      .zc-body { padding: 4px 18px 18px 68px; }
      .zc-foot { padding: 12px 14px; }
      .zc-btn { flex: 1; min-height: 44px; }
    }
  `;
  const styleEl = document.createElement('style');
  styleEl.setAttribute('data-zeronet-confirm', '1');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  const ICONS = {
    danger:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
  };

  function showModal(opts) {
    const variant = ['danger', 'warning', 'info'].includes(opts.variant) ? opts.variant : 'info';
    const title   = opts.title   || 'Konfirmasi';
    const message = opts.message || '';
    const action  = opts.action  || 'Konfirmasi';
    const cancel  = opts.cancel  || 'Batal';
    const needPwd = !!opts.requirePassword;
    const pwdLabel = opts.passwordLabel || 'Password Anda';

    return new Promise(function (resolve) {
      const backdrop = document.createElement('div');
      backdrop.className = 'zc-backdrop';
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');
      const pwdHtml = needPwd
        ? '<div class="zc-pwd-field">' +
            '<label class="zc-pwd-label" for="zc-pwd-input"></label>' +
            '<input id="zc-pwd-input" class="zc-pwd-input" type="password" autocomplete="current-password" />' +
            '<div class="zc-pwd-err" role="alert"></div>' +
          '</div>'
        : '';
      backdrop.innerHTML =
        '<div class="zc-modal">' +
          '<div class="zc-head">' +
            '<div class="zc-icon zc-' + variant + '">' + ICONS[variant] + '</div>' +
            '<h3 class="zc-title"></h3>' +
          '</div>' +
          '<div class="zc-body"><p></p>' + pwdHtml + '</div>' +
          '<div class="zc-foot">' +
            '<button type="button" class="zc-btn zc-btn-cancel"></button>' +
            '<button type="button" class="zc-btn zc-btn-confirm zc-' + variant + '"></button>' +
          '</div>' +
        '</div>';

      backdrop.querySelector('.zc-title').textContent = title;
      backdrop.querySelector('.zc-body p').textContent = message;
      backdrop.querySelector('.zc-btn-cancel').textContent = cancel;
      backdrop.querySelector('.zc-btn-confirm').textContent = action;

      const btnCancel  = backdrop.querySelector('.zc-btn-cancel');
      const btnConfirm = backdrop.querySelector('.zc-btn-confirm');
      const pwdInput   = needPwd ? backdrop.querySelector('#zc-pwd-input') : null;
      const pwdErr     = needPwd ? backdrop.querySelector('.zc-pwd-err')   : null;
      const origAction = action;
      let verifying = false;

      if (needPwd) {
        backdrop.querySelector('.zc-pwd-label').textContent = pwdLabel;
        btnConfirm.disabled = true;
        pwdInput.addEventListener('input', function () {
          btnConfirm.disabled = pwdInput.value.length === 0 || verifying;
          pwdInput.classList.remove('zc-err');
          pwdErr.classList.remove('zc-show');
        });
      }

      function close(ok) {
        document.removeEventListener('keydown', onKey);
        backdrop.classList.remove('zc-open');
        setTimeout(function () { backdrop.remove(); }, 180);
        if (needPwd) {
          resolve({ ok: ok, password: ok && pwdInput ? pwdInput.value : '' });
        } else {
          resolve(ok);
        }
      }

      function showPwdError(msg) {
        if (!pwdErr || !pwdInput) return;
        pwdErr.textContent = msg || 'Password salah.';
        pwdErr.classList.add('zc-show');
        pwdInput.classList.add('zc-err');
        pwdInput.focus();
        pwdInput.select();
      }

      function setVerifying(on) {
        verifying = on;
        btnConfirm.disabled = on || (needPwd && pwdInput.value.length === 0);
        btnConfirm.textContent = on ? 'Memverifikasi…' : origAction;
        if (pwdInput) pwdInput.disabled = on;
      }

      async function verifyPasswordRemote(password) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        const csrf = meta ? meta.getAttribute('content') : '';
        const urlMeta = document.querySelector('meta[name="verify-password-url"]');
        const verifyUrl = (urlMeta && urlMeta.getAttribute('content')) || '/admin/verify-password';
        try {
          const res = await fetch(verifyUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ password: password, intent: opts.intent || '' }),
          });
          if (res.ok) return { ok: true };
          if (res.status === 422) {
            const body = await res.json().catch(function () { return {}; });
            return { ok: false, message: body.message || 'Password salah.' };
          }
          if (res.status === 429) {
            return { ok: false, message: 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.' };
          }
          return { ok: false, message: 'Gagal verifikasi (HTTP ' + res.status + ').' };
        } catch (e) {
          return { ok: false, message: 'Tidak bisa menghubungi server. Cek koneksi Anda.' };
        }
      }

      function tryConfirm() {
        if (verifying) return;
        if (needPwd) {
          if (!pwdInput || pwdInput.value.length === 0) return;
          setVerifying(true);
          verifyPasswordRemote(pwdInput.value).then(function (r) {
            if (r.ok) {
              close(true);
            } else {
              setVerifying(false);
              showPwdError(r.message);
            }
          });
          return;
        }
        close(true);
      }

      function onKey(e) {
        if (e.key === 'Escape' && !verifying) close(false);
        if (e.key === 'Enter')  tryConfirm();
      }

      btnCancel.addEventListener('click', function () { if (!verifying) close(false); });
      btnConfirm.addEventListener('click', tryConfirm);
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop && !verifying) close(false);
      });
      document.addEventListener('keydown', onKey);

      document.body.appendChild(backdrop);
      requestAnimationFrame(function () { backdrop.classList.add('zc-open'); });
      setTimeout(function () {
        if (needPwd && pwdInput) pwdInput.focus();
        else btnConfirm.focus();
      }, 50);
    });
  }

  window.zeroConfirm = showModal;

  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    const msg = form.getAttribute('data-confirm');
    if (!msg) return;
    if (form.dataset.zcConfirmed === '1') return;

    const needPwd = form.getAttribute('data-confirm-password') === '1';

    e.preventDefault();
    showModal({
      title:   form.getAttribute('data-confirm-title')   || 'Konfirmasi',
      message: msg,
      action:  form.getAttribute('data-confirm-action')  || 'Konfirmasi',
      variant: form.getAttribute('data-confirm-variant') || 'info',
      requirePassword: needPwd,
      intent:  form.getAttribute('data-confirm-intent')
            || form.getAttribute('data-confirm-title')
            || '',
    }).then(function (result) {
      const ok = needPwd ? result.ok : result;
      if (!ok) return;

      if (needPwd) {
        let pwdInput = form.querySelector('input[name="current_password"][data-zc-injected="1"]');
        if (!pwdInput) {
          pwdInput = document.createElement('input');
          pwdInput.type = 'hidden';
          pwdInput.name = 'current_password';
          pwdInput.setAttribute('data-zc-injected', '1');
          form.appendChild(pwdInput);
        }
        pwdInput.value = result.password;
      }

      form.dataset.zcConfirmed = '1';
      form.submit();
    });
  }, true);

  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[data-confirm]');
    if (!a) return;
    if (a.dataset.zcConfirmed === '1') return;

    e.preventDefault();
    showModal({
      title:   a.getAttribute('data-confirm-title')   || 'Konfirmasi',
      message: a.getAttribute('data-confirm'),
      action:  a.getAttribute('data-confirm-action')  || 'Lanjut',
      variant: a.getAttribute('data-confirm-variant') || 'info',
    }).then(function (ok) {
      if (ok) {
        a.dataset.zcConfirmed = '1';
        window.location.href = a.href;
      }
    });
  }, true);
})();
