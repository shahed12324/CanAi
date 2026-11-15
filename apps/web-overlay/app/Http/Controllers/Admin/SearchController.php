<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;

final class SearchController extends AdminController
{
    public function __invoke(Request $request)
    {
        $term = trim((string) $request->query('q'));
        $users = $tenants = collect();

        if (mb_strlen($term) >= 2) {
            $like = $this->like($term);
            if ($request->user()->can('users.view')) {
                $users = $this->db()->table('users')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))
                    ->orderBy('name')->limit(10)->get(['id', 'name', 'email']);
            }
            if ($request->user()->can('tenants.view')) {
                $tenants = $this->db()->table('tenants')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('slug', 'ilike', $like))
                    ->orderBy('name')->limit(10)->get(['id', 'name', 'type']);
            }
        }

        return view('admin.search', ['q' => $term, 'users' => $users, 'tenants' => $tenants]);
    }
}
