<?php

use App\Brand\BrandService;
use Tests\Support\FakeBrandRepo;

function bsvc(): array
{
    $r = new FakeBrandRepo;

    return [new BrandService($r), $r];
}

function brow(array $o = []): array
{
    return $o + ['enabled' => true, 'brand_name' => 'Acme', 'color' => '#1a2b3c', 'support_email' => 'h@a.test', 'hide_powered_by' => true];
}

it('measures contrast with white text correctly', function () {
    expect(round(BrandService::contrastWithWhite('#000000'), 1))->toBe(21.0)->and(round(BrandService::contrastWithWhite('#ffffff'), 1))->toBe(1.0)
        ->and(BrandService::contrastWithWhite('#4f46e5'))->toBeGreaterThan(4.5)          // the default indigo passes
        ->and(BrandService::contrastWithWhite('#ffff00'))->toBeLessThan(2.0)
        ->and(BrandService::contrastWithWhite('#767676'))->toBeGreaterThan(4.5)           // the well-known "just passes" grey
        ->and(BrandService::contrastWithWhite('#777777'))->toBeLessThan(4.6);
});

it('uses the real WCAG formula (known reference values)', function () {
    expect(round(BrandService::contrastWithWhite('#767676'), 2))->toBe(4.54)->and(round(BrandService::contrastWithWhite('#777777'), 2))->toBe(4.48)->and(round(BrandService::contrastWithWhite('#808080'), 2))->toBe(3.95)->and(round(BrandService::contrastWithWhite('#4f46e5'), 2))->toBe(6.29);
});

it('refuses colours that are malformed or too light, before the database', function () {
    [$s, $r] = bsvc();
    foreach (['red', '#12345', '#GGGGGG', 'url(x)', '#1a2b3c;}'] as $c) {
        expect($s->save('t', 'u', 'N', $c, '', false))->toBe('bad_input');
    }
    expect($s->save('t', 'u', 'N', '#ffff00', '', false))->toBe('low_contrast')->and($r->saves)->toBe([]);
});

it('saves a good colour in lower case and passes empty values through as default', function () {
    [$s, $r] = bsvc();
    expect($s->save('t', 'u', 'Acme', ' #1A2B3C ', 'a@b.c', true))->toBe('ok')->and($r->saves[0])->toBe(['t', 'u', 'Acme', '#1a2b3c', 'a@b.c', true]);
    expect($s->save('t', 'u', '', '', '', false))->toBe('ok')->and($r->saves[1])->toBe(['t', 'u', '', '', '', false]);
});

it('passes the database answer through and has a message for each', function () {
    [$s, $r] = bsvc();
    foreach (['not_enabled', 'not_owner', 'bad_input'] as $a) {
        $r->saveResult = $a;
        expect($s->save('t', 'u', 'N', '', '', false))->toBe($a)->and(BrandService::message($a))->not->toBe('The action could not be done.');
    }
    expect(BrandService::message('low_contrast'))->toContain('Dark')->and(BrandService::message('zzz'))->toBe('The action could not be done.');
});

it('gives the layout nothing unless branding is on', function () {
    [$s, $r] = bsvc();
    expect($s->forLayout(null))->toBeNull()->and($s->forLayout('t'))->toBeNull();
    $r->row = brow(['enabled' => false]);
    expect($s->forLayout('t'))->toBeNull();
});

it('gives the layout the brand, and only a safe accent colour in the css', function () {
    [$s, $r] = bsvc();
    $r->row = brow();
    $b = $s->forLayout('t');
    expect($b['name'])->toBe('Acme')->and($b['hide_powered_by'])->toBeTrue()->and($b['support_email'])->toBe('h@a.test')->and($b['css'])->toContain('#1a2b3c')->and($b['css'])->toContain('!important');
    $r->row = brow(['color' => '#ffff00']);
    expect($s->forLayout('t')['color'])->toBeNull()->and($s->forLayout('t')['css'])->toBe('');           // an unreadable colour already in the DB is ignored
    $r->row = brow(['color' => 'red;}</style><script>']);
    expect($s->forLayout('t')['css'])->toBe('')->and($s->forLayout('t')['color'])->toBeNull();
    $r->row = brow(['color' => null, 'brand_name' => null]);
    expect($s->forLayout('t')['name'])->toBeNull()->and($s->forLayout('t')['css'])->toBe('');
});

it('never lets a database failure break a page', function () {
    [$s, $r] = bsvc();
    $r->throws = true;
    expect($s->forLayout('t'))->toBeNull();
});

it('css() accepts only #rrggbb', function () {
    expect(BrandService::css(null))->toBe('')->and(BrandService::css('#12345'))->toBe('')->and(BrandService::css('#1A2B3C'))->toBe('')->and(BrandService::css('#1a2b3c'))->toContain('background-color:#1a2b3c');
});
