<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Crm\TagNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Past work the workspace can attach to proposals. Nothing is hard-deleted. */
final class PortfolioController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'url' => ['nullable', 'url', 'max:300', 'starts_with:http://,https://'],
            'description' => ['nullable', 'string', 'max:600'],
            'tags' => ['nullable', 'string', 'max:300'],
        ];
    }

    private static function tagLiteral(?string $input): string
    {
        return '{'.implode(',', array_map(fn ($t) => '"'.$t.'"', TagNormalizer::parse((string) $input)['tags'])).'}';
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('proposals.view'), 403);

        return view('portfolio.index', [
            'items' => DB::select("select id, title, url, description, array_to_string(tags, ', ') as tags from portfolio_items where deleted_at is null order by created_at desc limit 200"),
            'canManage' => $request->user()->can('proposals.manage'),
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate($this->rules());
        DB::insert('insert into portfolio_items (id, tenant_id, title, url, description, tags) values (?, ?, ?, ?, ?, ?::text[])',
            [CreateWorkspace::newId(), $tenant->idOrFail(), trim($d['title']), $d['url'] ?? null, $d['description'] ?? null, self::tagLiteral($d['tags'] ?? null)]);

        return redirect()->route('portfolio.index')->with('status', 'Added to the portfolio.');
    }

    public function update(Request $request, string $id)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate($this->rules());
        $n = DB::update('update portfolio_items set title = ?, url = ?, description = ?, tags = ?::text[], updated_at = now() where id = ? and deleted_at is null',
            [trim($d['title']), $d['url'] ?? null, $d['description'] ?? null, self::tagLiteral($d['tags'] ?? null), $id]);
        abort_if($n === 0, 404);

        return redirect()->route('portfolio.index')->with('status', 'Saved.');
    }

    public function destroy(Request $request, string $id)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        abort_if(DB::update('update portfolio_items set deleted_at = now() where id = ? and deleted_at is null', [$id]) === 0, 404);

        return redirect()->route('portfolio.index')->with('status', 'Removed.');
    }
}
