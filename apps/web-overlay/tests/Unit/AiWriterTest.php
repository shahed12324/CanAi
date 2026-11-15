<?php

use App\Ai\ProviderCallException;
use App\Catalog\AiWriter;
use App\Catalog\AiWriterException;
use App\Chat\CreditPricing;
use Tests\Support\FakeEngine;
use Tests\Support\FakeLedger;
use Tests\Support\FakePicker;

function writer(FakeEngine $e, ?FakeLedger $l = null, ?FakePicker $p = null): array
{
    $l ??= new FakeLedger('100.0000');

    return [new AiWriter($p ?? new FakePicker, $e, $l, new CreditPricing('1', '3')), $l];
}

const J = '{"description":"সুতি টি-শার্ট","seo_title":"সুতি টি-শার্ট","seo_description":"নরম সুতি"}';

it('returns parsed copy, settles the real cost and leaves no reservation open', function () {
    [$w, $l] = writer(new FakeEngine([['text' => "```json\n".J."\n```", 'input_tokens' => 100, 'output_tokens' => 200]]));
    $r = $w->write('T', 'U', ['title' => 'Tee']);
    expect($r['description'])->toBe('সুতি টি-শার্ট')->and($r['credits'])->toBe('0.7000')->and($l->openReservations())->toBe(0)->and($l->balance)->toBe('99.3000');
});

it('releases the hold when the provider fails, the output is unusable, or the title is empty', function () {
    [$w, $l] = writer(new FakeEngine([new ProviderCallException('rate_limited', 'busy')]));
    expect(fn () => $w->write('T', 'U', ['title' => 'Tee']))->toThrow(AiWriterException::class);
    expect($l->openReservations())->toBe(0)->and($l->balance)->toBe('100.0000');

    [$w, $l] = writer(new FakeEngine([['text' => 'sorry, no json']]));
    expect(fn () => $w->write('T', 'U', ['title' => 'Tee']))->toThrow(AiWriterException::class);
    expect($l->openReservations())->toBe(0)->and($l->balance)->toBe('100.0000');

    [$w, $l] = writer($e = new FakeEngine([['text' => J]]));
    expect(fn () => $w->write('T', 'U', ['title' => '  ']))->toThrow(AiWriterException::class);
    expect($e->calls)->toBe(0);
});

it('stops without a model or without credit and never calls the engine', function () {
    [$w] = writer($e = new FakeEngine([['text' => J]]), null, new FakePicker(null));
    expect(fn () => $w->write('T', 'U', ['title' => 'Tee']))->toThrow(AiWriterException::class, 'Model');
    [$w] = writer($e = new FakeEngine([['text' => J]]), new FakeLedger('0.0000'));
    expect(fn () => $w->write('T', 'U', ['title' => 'Tee']))->toThrow(AiWriterException::class);
    expect($e->calls)->toBe(0);
});

it('clips overlong output and rejects an empty description', function () {
    $r = AiWriter::parse('x {"description":"d","seo_title":"'.str_repeat('a', 200).'","seo_description":5} y');
    expect(mb_strlen($r['seo_title']))->toBe(70)->and($r['seo_description'])->toBe('');
    expect(AiWriter::parse('{"description":"  "}'))->toBeNull()->and(AiWriter::parse('{broken'))->toBeNull()->and(AiWriter::parse('{"description":5}'))->toBeNull();
});

it('puts only the given facts in the prompt and honours language', function () {
    $m = AiWriter::messages(['title' => 'Tee', 'category' => 'Shirts', 'tags' => ['a', 'b'], 'language' => 'en']);
    expect($m[0]['content'])->toContain('English')->and($m[1]['content'])->toContain('Title: Tee')->and($m[1]['content'])->toContain('Category: Shirts')->and(str_contains($m[1]['content'], 'Price'))->toBeFalse();
});
