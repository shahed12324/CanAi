<?php

declare(strict_types=1);

namespace App\Calendar;

interface IcsFetcher
{
    /**
     * The text of a calendar feed.
     *
     * @throws IcsException with a short Bengali message
     */
    public function fetch(string $url): string;
}
