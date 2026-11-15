<?php

use App\Studio\ImagePricing;
use App\Studio\StudioService;
use App\Studio\StudioTemplates;
use App\Studio\StudioText;
use Tests\Support\FakeDesignRepo;
use Tests\Support\FakeImagePicker;
use Tests\Support\FakeLedger;
use Tests\Support\FakeStudioEngine;

function esvc(?FakeDesignRepo $r = null): array
{
    $r ??= new FakeDesignRepo;
    $l = new FakeLedger('100.0000');

    return [new StudioService(new FakeStudioEngine, new FakeImagePicker, $l, new ImagePricing('5'), $r), $r, $l];
}

function eseed(FakeDesignRepo $r, string $id = 'D00000000000000000000000AA', string $user = 'U1'): void
{
    $r->rows[$id] = ['id' => $id, 'user' => $user, 'title' => 'ঈদ', 'brief' => '', 'headline' => 'পুরনো', 'subline' => 's', 'cta' => 'c', 'theme' => 'dark', 'sizes' => ['post'],
        'brand' => [], 'logo_b64' => null, 'base_b64' => 'QkFTRQ==', 'created_at' => 'now'];
}

function ein(array $o = []): array
{
    return $o + ['title' => 'ঈদ', 'headline' => 'New', 'subline' => 's', 'cta' => 'c', 'theme' => 'dark', 'sizes' => ['post']];
}

const EID = 'D00000000000000000000000AA';

it('changes the words, keeps a version of the old state, never touches the picture or the credits', function () {
    [$s, $r, $l] = esvc();
    eseed($r);
    expect($s->edit('T', 'U1', EID, ein()))->toBe('ok');
    expect($r->rows[EID]['headline'])->toBe('New');
    expect($r->rows[EID]['base_b64'])->toBe('QkFTRQ==');
    expect($r->versionsOf[EID][0]['headline'])->toBe('পুরনো');
    expect($l->reservations ?? [])->toBe([]);
});

it('says unchanged and keeps no version when nothing is different (size order does not matter)', function () {
    [$s, $r] = esvc();
    eseed($r);
    $r->rows[EID]['sizes'] = ['story', 'post'];
    expect($s->edit('T', 'U1', EID, ein(['headline' => 'পুরনো', 'sizes' => ['post', 'story']])))->toBe('unchanged');
    expect($r->versionsOf[EID] ?? [])->toBe([]);
});

it('cleans the text like a new design and refuses bad input before the repo is asked', function () {
    [$s, $r] = esvc();
    eseed($r);
    $r->updateResult = 'ok';
    expect($s->edit('T', 'U1', EID, ein(["title" => "  \n "])))->toBe('bad_title');
    expect($s->edit('T', 'U1', EID, ein(['headline' => str_repeat('অ', 201)])))->toBe('bad_text');
    expect($s->edit('T', 'U1', EID, ein(['subline' => str_repeat('অ', 401)])))->toBe('bad_text');
    expect($s->edit('T', 'U1', EID, ein(['cta' => str_repeat('অ', 101)])))->toBe('bad_text');
    expect($s->edit('T', 'U1', EID, ein(['sizes' => []])))->toBe('bad_sizes');
    expect($s->edit('T', 'U1', EID, ein(['sizes' => ['huge', 5]])))->toBe('bad_sizes');
    expect($s->edit('T', 'U1', EID, ein(['theme' => 'neon'])))->toBe('bad_theme');
    expect($r->rows[EID]['headline'])->toBe('পুরনো');
});

it('accepts the exact limits, and dedupes sizes', function () {
    [$s, $r] = esvc();
    eseed($r);
    expect($s->edit('T', 'U1', EID, ein(['headline' => str_repeat('অ', 200), 'subline' => str_repeat('অ', 400), 'cta' => str_repeat('অ', 100), 'sizes' => ['post', 'post', 'ad']])))->toBe('ok');
    expect($r->rows[EID]['sizes'])->toBe(['post', 'ad']);
});

it('edits only my own design', function () {
    [$s, $r] = esvc();
    eseed($r);
    expect($s->edit('T', 'U2', EID, ein()))->toBe('not_found');
    expect($r->rows[EID]['headline'])->toBe('পুরনো');
    expect($s->restore('T', 'U2', EID, 1))->toBe('not_found');
});

it('reports a repo failure as failed, not as success', function () {
    [$s, $r] = esvc();
    eseed($r);
    $r->updateResult = 'bad_input';
    expect($s->edit('T', 'U1', EID, ein()))->toBe('failed');
});

it('restores an earlier version and keeps the current one so nothing is lost', function () {
    [$s, $r] = esvc();
    eseed($r);
    $s->edit('T', 'U1', EID, ein(['headline' => 'এক']));
    $s->edit('T', 'U1', EID, ein(['headline' => 'দুই']));
    expect($s->restore('T', 'U1', EID, 1))->toBe('ok');
    expect($r->rows[EID]['headline'])->toBe('পুরনো');
    expect(count($r->versionsOf[EID]))->toBe(3);
    expect($r->versionsOf[EID][2]['headline'])->toBe('দুই');
    expect($s->restore('T', 'U1', EID, 99))->toBe('not_found');
});

it('has Bengali messages for the new outcomes', function () {
    foreach (['unchanged', 'saved', 'restored', 'bad_title', 'bad_text', 'bad_sizes', 'bad_theme', 'not_found'] as $c) {
        expect(StudioText::message($c))->not->toBe('Could not be done.');
    }
});

it('offers templates that pass the same rules as a real design', function () {
    $all = StudioTemplates::all();
    expect(count($all) >= 5)->toBeTrue();
    foreach ($all as $k => $t) {
        expect(mb_strlen($t['name']) > 0)->toBeTrue();
        expect(mb_strlen($t['headline']) <= 200 && mb_strlen($t['subline']) <= 400 && mb_strlen($t['cta']) <= 100)->toBeTrue();
        expect(in_array($t['theme'], StudioService::THEMES, true))->toBeTrue();
        expect($t['sizes'] !== [] && array_diff($t['sizes'], StudioService::SIZES) === [])->toBeTrue();
        expect(preg_match('/[0-9০-৯]%|[০-৯0-9]+\s*Amount|৳/u', $t['headline'].$t['subline']))->toBe(0);   // no invented price or discount
        expect(StudioService::line($t['headline'], 200))->toBe($t['headline']);
    }
    expect(StudioTemplates::get('nope'))->toBeNull();
    expect(StudioTemplates::get(null))->toBeNull();
    expect(StudioTemplates::get('offer')['name'])->toBe($all['offer']['name']);
});


it('makes a logo idea through the same priced, settled-once picture path, and refuses an empty idea for free', function () {
    $e = new FakeStudioEngine;
    $l = new FakeLedger('100.0000');
    $s = new StudioService($e, new FakeImagePicker, $l, new ImagePricing('5'), new FakeDesignRepo);
    $bad = $s->logo('T', 'U1', ' a ', 'rq1');
    expect($bad['status'])->toBe('bad_prompt');
    expect($bad['credits'])->toBe('0.0000');
    expect($e->drawn)->toBe([]);
    $r = $s->logo('T', 'U1', 'কাজুবাদামের পাতা', 'rq2');
    expect($r['status'])->toBe('ok');
    expect($r['credits'])->toBe('5.0000');
    expect($e->drawn[0]["prompt"])->toContain('no text');
    expect($e->drawn[0]["prompt"])->toContain('কাজুবাদামের পাতা');
    expect($e->drawn[0]['size'])->toBe('1024x1024');
});
