<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Calendar\IcsException;
use App\Calendar\IcsFetcher;

final class FakeIcsFetcher implements IcsFetcher
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, string|\Throwable> $feeds url => text */
    public function __construct(public array $feeds = []) {}

    public function fetch(string $url): string
    {
        $this->urls[] = $url;
        $r = $this->feeds[$url] ?? new IcsException('পাওয়া যায়নি');
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return $r;
    }
}
