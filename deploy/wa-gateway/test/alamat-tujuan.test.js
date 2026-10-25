const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buatPemilihTujuan } = require('../alamat-tujuan');

const PN = '6281200099999@s.whatsapp.net';
const LID = '100000000000001@lid';

test('setelan kosong selalu memakai alamat nomor HP', () => {
  for (const nilai of ['', undefined, '  ']) {
    assert.equal(buatPemilihTujuan(nilai)(PN, { exists: true, lid: LID }), PN);
  }
});

test('nomor di daftar memakai alamat LID bila WhatsApp memberikannya', () => {
  assert.equal(buatPemilihTujuan('6281200099999')(PN, { exists: true, lid: LID }), LID);
});

test('daftar boleh berisi beberapa nomor dengan spasi', () => {
  const pilih = buatPemilihTujuan(' 6281200000001 , 6281200099999 ');
  assert.equal(pilih(PN, { exists: true, lid: LID }), LID);
});

test('nomor di luar daftar tetap memakai alamat nomor HP', () => {
  const pilih = buatPemilihTujuan('6281200099999');
  assert.equal(pilih('6281200099998@s.whatsapp.net', { exists: true, lid: '100000000000002@lid' }), '6281200099998@s.whatsapp.net');
});

test('semua berarti setiap nomor memakai LID bila ada', () => {
  const pilih = buatPemilihTujuan('semua');
  assert.equal(pilih('6281200099998@s.whatsapp.net', { exists: true, lid: '100000000000002@lid' }), '100000000000002@lid');
});

test('tanpa LID dari WhatsApp tetap memakai alamat nomor HP', () => {
  const pilih = buatPemilihTujuan('semua');
  for (const cek of [{ exists: true }, { exists: true, lid: '' }, { exists: true, lid: null }, undefined]) {
    assert.equal(pilih(PN, cek), PN);
  }
});

test('LID yang bentuknya tidak wajar diabaikan', () => {
  const pilih = buatPemilihTujuan('semua');
  for (const lid of ['100000000000001@s.whatsapp.net', '12287@g.us', 'abc@lid', '@lid', 12345]) {
    assert.equal(pilih(PN, { exists: true, lid }), PN);
  }
});

test('LID dengan nomor perangkat dinormalkan ke alamat pengguna', () => {
  const pilih = buatPemilihTujuan('semua');
  assert.equal(pilih(PN, { exists: true, lid: '100000000000001:15@lid' }), LID);
});
