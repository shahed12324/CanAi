<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Base for admin controllers: every query goes through the separate admin connection. */
abstract class AdminController extends Controller
{
    protected function db(): Connection
    {
        return DB::connection('admin');
    }

    protected function allow(string $permission): void
    {
        abort_unless(request()->user()->can($permission), 403);
    }

    /** "%term%" with LIKE wildcards in the term escaped. */
    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }

    /** @return array<string, mixed>|null */
    protected function snapshot(?object $row, array $only): ?array
    {
        return $row === null ? null : array_intersect_key((array) $row, array_flip($only));
    }
}
