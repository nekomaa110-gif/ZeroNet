const BATAS_HTML = 200 * 1024;
const TUNGGU_SIAP_MS = 10 * 1000;
const TENGGAT_MS = 40 * 1000;

function buatHandlerKirimHtml({ getSock, waitReady, renderHtml, normalizeJid, rememberSent, jeda, now = Date.now, logger, pilihTujuan = (jid) => jid }) {
  return async (req, res) => {
    const mulai = now();
    let klienPutus = false;
    res.on('close', () => { if (!res.writableEnded) klienPutus = true; });

    const { number, html, caption, viewport } = req.body || {};
    if (!number || typeof html !== 'string' || html === '' || Buffer.byteLength(html, 'utf8') > BATAS_HTML) {
      return res.status(400).json({ ok: false, error: 'number dan html (maks 200 KB) wajib', code: 'invalid_request' });
    }

    if (!(await waitReady(TUNGGU_SIAP_MS))) {
      return res.status(503).json({ ok: false, error: 'wa_not_ready' });
    }

    let jid;
    try {
      jid = normalizeJid(number);
      const [check] = await getSock().onWhatsApp(jid);
      if (!check?.exists) return res.status(404).json({ ok: false, error: 'number_not_on_whatsapp' });
      jid = pilihTujuan(jid, check);
    } catch (e) {
      logger.error({ to: jid, code: 'check_failed', err: e.message }, 'send_image_html_failed');
      return res.status(500).json({ ok: false, error: e.message, code: 'check_failed' });
    }

    let png;
    try {
      png = await renderHtml(html, viewport || {});
    } catch (e) {
      logger.error({ to: jid, code: 'render_failed', err: e.message }, 'send_image_html_failed');
      return res.status(500).json({ ok: false, error: e.message, code: 'render_failed' });
    }

    await jeda();

    if (now() - mulai > TENGGAT_MS) {
      logger.warn({ to: jid, code: 'deadline_exceeded' }, 'send_image_html_failed');
      return res.status(500).json({ ok: false, error: 'deadline exceeded', code: 'deadline_exceeded' });
    }

    if (klienPutus) {
      logger.warn({ to: jid, code: 'client_gone' }, 'send_image_html_batal');
      return;
    }

    try {
      const r = await getSock().sendMessage(jid, { image: png, caption: caption ? String(caption) : undefined });
      rememberSent(r.key.id, r.message);
      logger.info({ to: jid, id: r.key.id, png_bytes: png.length }, 'sent_image_html');
      return res.json({ ok: true, id: r.key.id, png_bytes: png.length });
    } catch (e) {
      logger.error({ to: jid, code: 'send_failed', err: e.message }, 'send_image_html_failed');
      return res.status(500).json({ ok: false, error: e.message, code: 'send_failed' });
    }
  };
}

module.exports = { buatHandlerKirimHtml };
