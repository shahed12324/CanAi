<?php

declare(strict_types=1);

use App\DocTools\Catalog;

it('every tool is complete and every field is well formed', function () {
    foreach (Catalog::all() as $k => $t) {
        expect(preg_match('/^[a-z0-9_]+$/', $k))->toBe(1);
        expect(array_keys(Catalog::GROUPS))->toContain($t['group']);
        expect($t['min'])->toBeGreaterThanOrEqual(0)->and($t['max'])->toBeGreaterThanOrEqual($t['min']);
        expect(array_keys(Catalog::EXT))->toContain($t['accept']);
        foreach ($t['fields'] as $f) {
            expect(['select', 'number', 'text', 'textarea', 'checkbox'])->toContain($f['type']);
            if ($f['type'] === 'select') {
                expect($f['options'])->not->toBeEmpty()->and(array_key_exists((string) $f['default'], $f['options']))->toBeTrue();
            }
        }
    }
});

it('covers the full requested tool list', function () {
    expect(count(Catalog::all()))->toBeGreaterThanOrEqual(43);
});

it('only passes declared settings, cast and clamped by type, and fixed values win', function () {
    $t = Catalog::get('passport_photo');
    $p = Catalog::params($t, ['size' => 'custom', 'width_mm' => '40', 'zoom' => '1.5', 'evil' => 'x', 'bg' => ' #fff ', 'format' => 'exe']);
    expect($p)->toMatchArray(['size' => 'custom', 'width_mm' => 40.0, 'zoom' => 1.5, 'bg' => '#fff'])->and($p)->not->toHaveKey('evil')->and($p)->not->toHaveKey('format');
    $f = Catalog::params(Catalog::get('photo_35x45'), ['size' => 'custom']);
    expect($f['size'])->toBe('35x45');
    expect(Catalog::params(Catalog::get('nid_combine'), ['cleanup' => '1'])['cleanup'])->toBeTrue();
    expect(Catalog::params(Catalog::get('nid_combine'), [])['cleanup'])->toBeFalse();
});

it('accepts only the right file types', function () {
    expect(Catalog::accepts(Catalog::get('pdf_merge'), 'a.PDF'))->toBeTrue()->and(Catalog::accepts(Catalog::get('pdf_merge'), 'a.png'))->toBeFalse();
    expect(Catalog::accepts(Catalog::get('doc_to_pdf'), 'x.docx'))->toBeTrue()->and(Catalog::accepts(Catalog::get('doc_to_pdf'), 'x.exe'))->toBeFalse();
    expect(Catalog::accepts(Catalog::get('image_crop'), 'x.svg'))->toBeFalse();
});

it('checks files against what the tool takes', function () {
    $merge = Catalog::get('pdf_merge');
    expect(App\DocTools\ToolRunner::check($merge, ['a.pdf']))->toContain('at least 2');
    expect(App\DocTools\ToolRunner::check($merge, ['a.pdf', 'b.pdf']))->toBeNull();
    expect(App\DocTools\ToolRunner::check($merge, ['a.pdf', 'b.png']))->toContain('not a type');
    expect(App\DocTools\ToolRunner::check(Catalog::get('qr_code'), []))->toBeNull();
    expect(App\DocTools\ToolRunner::check(Catalog::get('qr_code'), ['a.png']))->toContain('does not take');
    expect(App\DocTools\ToolRunner::check(Catalog::get('signature'), []))->toBeNull();
});

it('keeps free text up to its own limit', function () {
    $p = Catalog::params(Catalog::get('qr_code'), ['text' => str_repeat('a', 2000), 'size' => '900']);
    expect(strlen($p['text']))->toBe(1000)->and($p['size'])->toBe(900);
});
