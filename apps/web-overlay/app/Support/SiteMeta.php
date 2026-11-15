<?php

declare(strict_types=1);

namespace App\Support;

/** Site name, SEO tags, extra menu links and footer content, all editable in Admin → Settings → "Site, SEO, menu and footer". */
final class SiteMeta
{
    public static function name(): string
    {
        return (string) (SystemSettings::get('branding.name') ?: config('brand.name', 'CANAI'));
    }

    public static function title(?string $page = null): string
    {
        $name = self::name();
        $seo = trim((string) SystemSettings::get('site.seo_title', ''));
        if ($page !== null && $page !== '' && $page !== $name) {
            return $page.' · '.$name;
        }

        return $seo !== '' ? $seo : $name;
    }

    /** Meta tags block (escaped). */
    public static function tags(?string $page = null): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = self::title($page);
        $desc = trim((string) SystemSettings::get('site.seo_description', ''));
        $kw = trim((string) SystemSettings::get('site.seo_keywords', ''));
        $img = trim((string) SystemSettings::get('site.og_image', ''));
        $index = SystemSettings::bool('site.allow_indexing', true);
        $out = '<meta name="robots" content="'.($index ? 'index,follow' : 'noindex,nofollow').'">';
        if ($desc !== '') {
            $out .= '<meta name="description" content="'.$e($desc).'"><meta property="og:description" content="'.$e($desc).'">';
        }
        if ($kw !== '') {
            $out .= '<meta name="keywords" content="'.$e($kw).'">';
        }
        $out .= '<meta property="og:title" content="'.$e($title).'"><meta property="og:site_name" content="'.$e(self::name()).'"><meta property="og:type" content="website">';
        if (preg_match('#^https://#i', $img) === 1) {
            $out .= '<meta property="og:image" content="'.$e($img).'"><meta name="twitter:card" content="summary_large_image">';
        }

        return $out;
    }

    /** @return list<array{label:string,url:string}> only http(s) or site-relative URLs are kept. */
    public static function links(string $key): array
    {
        return self::parseLinks((string) SystemSettings::get($key, ''));
    }

    /** @return list<array{label:string,url:string}> */
    public static function parseLinks(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($label !== '' && preg_match('#^(https?://|/)[^\s]*$#i', $url) === 1) {
                $out[] = ['label' => mb_substr($label, 0, 40), 'url' => $url];
            }
        }

        return array_slice($out, 0, 12);
    }

    public static function footerText(): string
    {
        return trim((string) SystemSettings::get('site.footer_text', '')) ?: '© '.date('Y').' '.self::name();
    }
}
