<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

class PembersihHtml
{
    private const TAG_TERLARANG = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta', 'base',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'svg', 'math', 'template', 'portal',
        'audio', 'video', 'source', 'track', 'noscript', 'title', 'dialog', 'style',
    ];

    private const ATRIBUT_URL = [
        'href', 'src', 'action', 'formaction', 'background', 'poster', 'srcset', 'lowsrc', 'dynsrc',
        'xlink:href', 'data', 'cite', 'longdesc', 'usemap', 'ping', 'manifest', 'codebase',
    ];

    private const ATRIBUT_TERLARANG = ['id', 'name', 'srcdoc', 'is', 'form'];

    private array $dibuang = [];

    public function bersihkan(string $html): string
    {
        $this->dibuang = [];

        $token = [];
        $html  = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use (&$token) {
            $kunci         = 'znph' . count($token) . 'zn';
            $token[$kunci] = '{{' . $m[1] . '}}';

            return $kunci;
        }, $html);

        $dom  = new DOMDocument();
        $lama = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div data-zn-akar="1">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($lama);

        $akar = (new DOMXPath($dom))->query('//div[@data-zn-akar="1"]')->item(0);
        if (! $akar instanceof DOMElement) {
            return '';
        }

        foreach ((new DOMXPath($dom))->query('.//*', $akar) as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($el->localName);
            if (in_array($tag, self::TAG_TERLARANG, true)) {
                $this->catat("<{$tag}>");
                $el->parentNode?->removeChild($el);
                continue;
            }

            foreach (iterator_to_array($el->attributes) as $attr) {
                $nama  = strtolower($attr->nodeName);
                $nilai = (string) $attr->nodeValue;

                $buang = str_starts_with($nama, 'on')
                    || in_array($nama, self::ATRIBUT_TERLARANG, true)
                    || (in_array($nama, self::ATRIBUT_URL, true) && ! self::urlAman($nilai));

                if ($nama === 'style' && ! $buang) {
                    $css = self::bersihkanCss($nilai);
                    if ($css !== $nilai) {
                        $this->catat('style berisi url/expression');
                    }
                    $css === '' ? $el->removeAttribute($attr->nodeName) : $el->setAttribute($attr->nodeName, $css);
                    continue;
                }

                if ($buang) {
                    $this->catat("atribut {$nama}");
                    $el->removeAttribute($attr->nodeName);
                }
            }
        }

        $hasil = '';
        foreach ($akar->childNodes as $anak) {
            $hasil .= $dom->saveHTML($anak);
        }

        return strtr($hasil, $token);
    }

    public function dibuang(): array
    {
        return array_values(array_unique($this->dibuang));
    }

    public static function bersihkanCss(string $css): string
    {
        $css = str_replace('\\', '', $css);
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $css = preg_replace('/@import[^;]*;?/i', '', $css);
        $css = preg_replace('/(expression|javascript|vbscript|behavior|-moz-binding)\s*[:(]/i', 'x-', $css);
        $css = preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) {
            return preg_match('#^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=\s]+$#i', trim($m[2])) ? $m[0] : 'none';
        }, $css);

        $css = preg_replace('#(?:[a-z][a-z0-9+.\-]*:)?//[^\s;)\'"]*#i', '', $css);

        return trim(str_ireplace('</style', '', $css));
    }

    public static function urlAman(string $url): bool
    {
        $u = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5)));

        if ($u === '' || preg_match('#^data:image/(png|gif|jpe?g|webp);base64,#', $u)) {
            return true;
        }

        return ! str_starts_with($u, '//') && ! preg_match('#^[a-z][a-z0-9+.\-]*:#', $u) && ! str_contains($u, '\\');
    }

    private function catat(string $apa): void
    {
        $this->dibuang[] = $apa;
    }
}
