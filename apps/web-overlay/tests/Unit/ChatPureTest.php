<?php

use App\Chat\ContextBuilder;
use App\Chat\CreditPricing;
use App\Chat\SseParser;
use App\Chat\TokenEstimator;

it('prices tokens in whole 0.0001 credits, rounding up', function (int $in, int $out, string|float|null $mult, string $expect) {
    expect((new CreditPricing('1', '3'))->cost($in, $out, $mult))->toBe($expect);
})->with([
    [1000, 0, null, '1.0000'],
    [0, 1000, null, '3.0000'],
    [1000, 1000, 1.5, '6.0000'],
    [1, 0, null, '0.0010'],
    [0, 0, null, '0.0000'],
    [333, 0, '0.3333', '0.1110'],     // 333/1000 x 0.3333 = 0.1109889 -> rounds up
    [1500, 500, '2.0000', '6.0000'],
]);

it('never reserves zero and rejects bad multipliers', function () {
    $p = new CreditPricing('1', '3');
    expect($p->reserveAmount(0, 0))->toBe('0.0001')
        ->and(fn () => $p->cost(1, 1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $p->cost(-1, 1))->toThrow(InvalidArgumentException::class);
});

it('estimates Bengali more expensively than ASCII', function () {
    expect(TokenEstimator::estimate(''))->toBe(0)
        ->and(TokenEstimator::estimate(str_repeat('a', 400)))->toBe(100)
        ->and(TokenEstimator::estimate(str_repeat('অ', 150)))->toBe(100);
});

it('parses SSE across arbitrary chunk boundaries', function () {
    $p = new SseParser;
    $all = [];
    foreach (["event: delta\ndata: {\"te", "xt\":\"Hi\"}\n\nevent: do", "ne\r\ndata: {}\r\n\r\n", ": keepalive\n\n"] as $chunk) {
        array_push($all, ...$p->feed($chunk));
    }
    expect($all)->toHaveCount(3)
        ->and($all[0])->toBe(['event' => 'delta', 'data' => ['text' => 'Hi']])
        ->and($all[1]['event'])->toBe('done')
        ->and($all[2]['data'])->toBe([]);
});

it('keeps the newest turns within the budget and starts with a user turn', function () {
    $history = [];
    for ($i = 1; $i <= 20; $i++) {
        $history[] = ['role' => 'user', 'content' => "question $i ".str_repeat('x', 200)];
        $history[] = ['role' => 'assistant', 'content' => "answer $i ".str_repeat('y', 200)];
    }
    $history[] = ['role' => 'user', 'content' => 'latest question'];
    $r = ContextBuilder::build($history, 600);

    expect($r['truncated'])->toBeTrue()
        ->and($r['messages'][0]['role'])->toBe('system')
        ->and($r['messages'][1]['role'])->toBe('user')
        ->and(end($r['messages'])['content'])->toBe('latest question')
        ->and($r['input_tokens'])->toBeLessThan(700);
});

it('includes attachments and trims an oversized newest message from the front', function () {
    $r = ContextBuilder::build([['role' => 'user', 'content' => 'summarise', 'attachments' => [['filename' => 'a.txt', 'text' => 'FILE BODY']]]], 5000);
    expect(end($r['messages'])['content'])->toContain('summarise')->and(end($r['messages'])['content'])->toContain('[Attached file: a.txt]')->and($r['truncated'])->toBeFalse();

    $big = ContextBuilder::build([['role' => 'user', 'content' => str_repeat('word ', 5000).'THE-END']], 500);
    expect(end($big['messages'])['content'])->toContain('THE-END')->and($big['truncated'])->toBeTrue()->and($big['input_tokens'])->toBeLessThan(600);
});
