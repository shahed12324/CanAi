<?php

use App\Catalog\PgCatalogRepo;

it('round-trips postgres text arrays with commas, quotes, backslashes and Bangla', function () {
    $in = ['a b', 'c,d', 'q"x', 'back\\slash', 'সুতি', ''];
    expect(PgCatalogRepo::pgArray(PgCatalogRepo::toPgArray($in)))->toBe($in);
    expect(PgCatalogRepo::toPgArray([]))->toBe('{}')->and(PgCatalogRepo::pgArray('{}'))->toBe([]);
});
