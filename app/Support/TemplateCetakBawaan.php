<?php

namespace App\Support;

class TemplateCetakBawaan
{
    private const GAYA = <<<'HTML'
<style>
table.voucher { display: inline-block; border: 2px solid black; margin: 2px; }
table.voucher .num { float: right; display: inline-block; }
</style>
HTML;

    private const BARIS_UP = <<<'HTML'
                  {{#up}}
                  <tr>
                    <td style="width: 50%">Username</td>
                    <td>Password</td>
                  </tr>
                  <tr style="color: black; font-size: 14px;">
                    <td style="border: 1px solid black; font-weight: bold;">{{kode}}</td>
                    <td style="border: 1px solid black; font-weight: bold;">{{password}}</td>
                  </tr>
                  <tr>
                    <td colspan="2" style="text-align: center;"><span style="font-weight: bold; padding: 2px 6px;"> {{batas_waktu}} <b>•</b> {{harga}}</span></td>
                  </tr>
                  <tr>
                    <td colspan="2" style="padding: 0;"><hr style="border: 0; border-top: 1px solid black; margin: 0; width: 100%;"></td>
                  </tr>
                  <tr>
                    <td colspan="2" style="text-align: center; padding-top: 2px; font-size: 7px;"><i>Masa berlaku: {{masa}}</i></td>
                  </tr>
                  {{/up}}
HTML;

    public static function semua(): array
    {
        return [
            'mikhmon-standar' => [
                'name'    => 'Standar (kuning)',
                'per_row' => 0,
                'html'    => self::kartu(
                    'width: 160px; background-color: #ffcc00ff; font-family: Georgia, serif;',
                    <<<'HTML'
                  {{#vc}}
                  <tr>
                    <td style="position: relative; top: -3px;">Kode Voucher</td>
                  </tr>
                  <tr style="color: black; font-size: 14px;">
                    <td style="width: 100%; border: 1px solid black; font-weight: bold;">{{kode}}</td>
                  </tr>
                  <tr>
                    <td colspan="2" style="text-align: center;"><span style="font-weight: bold; padding: 2px 6px;"> {{batas_waktu}} <b>•</b> {{harga}}</span></td>
                  </tr>
                  {{/vc}}
HTML
                ),
            ],
            'mikhmon-kecil' => [
                'name'    => 'Kecil (hijau)',
                'per_row' => 0,
                'html'    => self::kartu('width: 160px; background-color: #25D366; font-family: Georgia, serif;', self::vcRingkas()),
            ],
            'mikhmon-thermal' => [
                'name'    => 'Thermal',
                'per_row' => 0,
                'html'    => self::kartu('width: 160px;', self::vcRingkas()),
            ],
        ];
    }

    private static function vcRingkas(): string
    {
        return <<<'HTML'
                  {{#vc}}
                  <tr>
                    <td>Kode Voucher</td>
                  </tr>
                  <tr style="color: black; font-size: 14px;">
                    <td style="width: 100%; border: 1px solid black; font-weight: bold;">{{kode}}</td>
                  </tr>
                  <tr>
                    <td colspan="2" style="border: 1px solid black; font-weight: bold;">{{masa_asli}} {{batas_waktu_asli}} {{harga}}</td>
                  </tr>
                  {{/vc}}
HTML;
    }

    private static function kartu(string $gaya, string $barisVc): string
    {
        $up = self::BARIS_UP;

        return self::GAYA . "\n" . <<<HTML
<table class="voucher" style="{$gaya}">
  <tbody>
    <tr>
      <td style="text-align: left; font-size: 10px; font-weight: bold; border-bottom: 1px black solid;">Login http://{{hotspot}}<span class="num"> [{{nomor}}]</span></td>
    </tr>
    <tr>
      <td>
        <table style="text-align: center; width: 150px;">
          <tbody>
            <tr style="color: black; font-size: 11px;">
              <td>
                <table style="width: 100%;">
{$barisVc}
{$up}
                </table>
              </td>
            </tr>
          </tbody>
        </table>
      </td>
    </tr>
  </tbody>
</table>
HTML;
    }
}
