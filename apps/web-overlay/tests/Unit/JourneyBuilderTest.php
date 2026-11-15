<?php

declare(strict_types=1);

use App\Journey\JourneyBuilder as J;

function item(string $kind, string $at, string $title, string $detail = ''): array
{
    return ['kind' => $kind, 'at' => $at, 'title' => $title, 'detail' => $detail, 'url' => '/x'];
}

it('sorts newest first and filters by kind and search', function () {
    $all = [item('memory', '2026-11-01 10:00', 'Likes short answers'), item('skill', '2026-11-12 09:00', 'Skill /sales v2', 'from feedback'), item('job', '2026-11-13 08:00', 'Daily summary'), item('memory', '2026-11-12 09:00', 'Shop in Bashundhara')];
    expect(array_column(J::timeline($all), 'title'))->toBe(['Daily summary', 'Shop in Bashundhara', 'Skill /sales v2', 'Likes short answers']);
    expect(array_column(J::timeline($all, 'memory'), 'title'))->toBe(['Shop in Bashundhara', 'Likes short answers']);
    expect(array_column(J::timeline($all, '', 'FEEDBACK'), 'title'))->toBe(['Skill /sales v2']);
    expect(array_column(J::timeline($all, '', 'বাসা'), 'title'))->toBe([]);
    expect(count(J::timeline($all, '', '', 2)))->toBe(2);
});

it('exports memories and skills as Markdown and JSON', function () {
    $mem = [['content' => "Likes short\nanswers", 'category' => 'preference', 'scope' => 'user', 'pinned' => true, 'active' => true, 'secret' => 'x'], ['content' => 'Shop in Dhaka', 'category' => 'fact', 'scope' => 'business', 'pinned' => false, 'active' => false]];
    $sk = [['slug' => 'sales', 'name' => 'Sales summary', 'description' => 'weekly', 'instructions' => "1. Look\n2. Sum", 'version' => 3, 'user_id' => 'u1']];
    $md = J::markdown($mem, $sk, '2026-11-13');
    expect($md)->toContain('- Likes short answers _(preference, important)_')->and($md)->toContain('business, turned off')->and($md)->toContain('### /sales — Sales summary')->and($md)->toContain("1. Look\n2. Sum");
    expect(J::markdown([], [], 'd'))->toContain('_None._');
    $j = json_decode(J::json($mem, $sk, 'd'), true);
    expect($j['memories'][0])->not->toHaveKey('secret')->and($j['skills'][0])->not->toHaveKey('user_id')->and($j['skills'][0]['slug'])->toBe('sales');
});
