const LID_WAJAR = /^([0-9]{5,20})(?::[0-9]{1,3})?@lid$/;

function buatPemilihTujuan(setelan) {
  const nilai = String(setelan || '').trim();
  const semua = nilai === 'semua';
  const nomor = new Set(semua ? [] : nilai.split(',').map((n) => n.trim()).filter(Boolean));

  return (jidPn, cek) => {
    if (!semua && !nomor.has(String(jidPn).split('@')[0])) return jidPn;
    const cocok = typeof cek?.lid === 'string' ? cek.lid.match(LID_WAJAR) : null;
    return cocok ? cocok[1] + '@lid' : jidPn;
  };
}

module.exports = { buatPemilihTujuan };
