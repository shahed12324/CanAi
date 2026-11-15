<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Leads\LeadEngine;

final class FakeLeadEngine implements LeadEngine
{
    /** @var array<string, array<string, mixed>|\Throwable> domain => result */
    public array $results = [];

    public array $calls = [];

    public function crawl(string $tenantId, string $userId, string $url, int $maxPages, bool $renderJs): array
    {
        $this->calls[] = compact('tenantId', 'userId', 'url', 'maxPages', 'renderJs');
        $r = $this->results[parse_url($url, PHP_URL_HOST)] ?? ['status' => 'unreachable'];
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return $r;
    }

    public static function okResult(string $domain, array $over = []): array
    {
        return array_replace([
            'status' => 'ok', 'url' => "https://$domain/", 'final_url' => "https://$domain/", 'domain' => $domain, 'pages_fetched' => 2, 'score' => 70,
            'company' => ['name' => 'আলো ডিজিটাল', 'description' => 'desc', 'address' => 'Dhaka'],
            'emails' => [['email' => "info@$domain", 'status' => 'mx_ok', 'same_domain' => true, 'source_url' => "https://$domain/"]],
            'phones' => [['e164' => '+8801712345678', 'type' => 'mobile', 'source_url' => "https://$domain/contact"]],
            'social' => ['facebook' => 'https://facebook.com/x'], 'tech' => ['wordpress'], 'tracking' => ['ga' => false], 'has_form' => true,
            'gaps' => [['code' => 'no_fb_pixel', 'label' => 'x', 'weight' => 6, 'service' => 'Meta Ads']],
        ], $over);
    }
}
