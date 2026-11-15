<?php

use App\Support\SiteMeta;

it('keeps only safe menu/footer links and caps their number', function () {
    $raw = "Blog | https://canai.example/blog\nHelp | /help\nBad | javascript:alert(1)\nNoUrl\n | https://x.y\nEvil | data:text/html,hi";
    expect(SiteMeta::parseLinks($raw))->toBe([
        ['label' => 'Blog', 'url' => 'https://canai.example/blog'],
        ['label' => 'Help', 'url' => '/help'],
    ]);
    expect(SiteMeta::parseLinks(str_repeat("A | /a\n", 30)))->toHaveCount(12);
});
