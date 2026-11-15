<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalog\AiWriter;
use App\Catalog\AiWriterException;
use App\Catalog\CatalogCsv;
use App\Catalog\CatalogRepo;
use App\Catalog\CatalogService;
use App\Proposals\Money;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Product list, editor, variants, stock, approval workflow, CSV import and the AI description helper. */
final class CatalogController extends Controller
{
    public const STATUS_BN = ['draft' => 'draft', 'pending_approval' => 'Awaiting approval', 'approved' => 'Allowed', 'published' => 'Published', 'archived' => 'Archive'];

    public function __construct(private readonly CatalogService $svc, private readonly CatalogRepo $repo, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->getAuthIdentifier();
    }

    private function need(Request $r, string $permission): void
    {
        abort_unless($r->user()->can($permission), 403);
    }

    public function index(Request $request)
    {
        $this->need($request, 'catalog.view');
        $status = $request->query('status');
        $status = is_string($status) && isset(self::STATUS_BN[$status]) ? $status : null;
        $q = trim((string) $request->query('q', ''));

        return view('catalog.index', [
            'rows' => $this->repo->list($status, $q === '' ? null : mb_substr($q, 0, 80), 100),
            'statusBn' => self::STATUS_BN, 'status' => $status, 'q' => $q,
            'canManage' => $request->user()->can('catalog.manage'), 'canApprove' => $request->user()->can('catalog.approve'),
        ]);
    }

    public function create(Request $request)
    {
        $this->need($request, 'catalog.manage');

        return view('catalog.edit', $this->viewData($request, null));
    }

    public function store(Request $request)
    {
        $this->need($request, 'catalog.manage');
        $r = $this->svc->create($this->tenant->idOrFail(), $this->uid($request), $this->input($request));
        if (isset($r['errors'])) {
            return back()->withErrors($r['errors'])->withInput();
        }

        return redirect()->route('catalog.edit', $r['id'])->with('status', 'The product was created. Fix the description, price and stock and send for approval.');
    }

    public function edit(Request $request, string $id)
    {
        $this->need($request, 'catalog.view');
        $p = $this->repo->find(self::id($id)) ?? abort(404);

        return view('catalog.edit', $this->viewData($request, $p));
    }

    public function update(Request $request, string $id)
    {
        $this->need($request, 'catalog.manage');
        $r = $this->svc->update(self::id($id), $this->input($request));
        if (isset($r['errors'])) {
            return back()->withErrors($r['errors'])->withInput();
        }
        $p = $this->repo->find($id);

        return back()->with('status', $p !== null && $p['status'] === 'draft' ? 'Saved. Changing an approved/published product needs approval again.' : 'Saved.');
    }

    public function destroy(Request $request, string $id)
    {
        $this->need($request, 'catalog.manage');
        $p = $this->repo->find(self::id($id)) ?? abort(404);
        abort_if(in_array($p['status'], ['pending_approval', 'approved', 'published'], true), 422, 'Archive it first.');
        $this->repo->softDelete($id);

        return redirect()->route('catalog.index')->with('status', 'The product was deleted.');
    }

    public function workflow(Request $request, string $id, string $action)
    {
        $id = self::id($id);
        abort_unless(in_array($action, ['submit', 'approve', 'reject', 'publish', 'archive', 'restore'], true), 404);
        $this->need($request, in_array($action, ['approve', 'reject', 'publish'], true) ? 'catalog.approve' : 'catalog.manage');
        $this->repo->find($id) ?? abort(404);
        $u = $this->uid($request);
        $note = $action === 'reject' ? mb_substr(trim((string) $request->input('note', '')), 0, 300) : null;
        $res = match ($action) {
            'submit' => $this->repo->submit($id, $u),
            'approve', 'reject' => $this->repo->decide($id, $u, $action, $note),
            'publish' => $this->repo->publish($id, $u),
            'archive' => $this->repo->archive($id, $u),
            'restore' => $this->repo->restore($id, $u),
        };
        $ok = in_array($res, ['submitted', 'approved', 'rejected', 'published', 'archived', 'restored'], true);

        return back()->with($ok ? 'status' : 'error', CatalogService::message($res));
    }

    public function variantStore(Request $request, string $id)
    {
        $this->need($request, 'catalog.manage');
        $p = $this->repo->find(self::id($id)) ?? abort(404);
        abort_if($p['status'] === 'archived', 422);
        $options = CatalogCsv::options((string) $request->input('options', ''));
        if ($options === null || $options === []) {
            return back()->withErrors(['options' => 'Write the option, for example: Size=M;Color=Red'])->withInput();
        }
        $price = null;
        if (trim((string) $request->input('price', '')) !== '') {
            $price = Money::parse((string) $request->input('price'));
            if ($price === null) {
                return back()->withErrors(['price' => 'The price could not be understood.'])->withInput();
            }
        }
        $sku = trim((string) $request->input('sku', ''));
        abort_if(mb_strlen($sku) > 80, 422);
        try {
            // the default "no options" variant is retired when the first real variant arrives
            foreach ($p['variants'] as $v) {
                if ($v['options'] === [] && (int) $v['stock_qty'] === 0) {
                    $this->repo->archiveVariant($v['id']);
                }
            }
            $vid = $this->repo->addVariant($this->tenant->idOrFail(), $id, $sku === '' ? null : $sku, $options, $price, $request->boolean('track_stock', true));
            $qty = (int) $request->input('stock', 0);
            if ($qty > 0 && $qty <= 1_000_000) {
                $this->repo->stock($vid, $qty, 'initial', null, $this->uid($request));
            }
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withErrors(['options' => CatalogService::friendly($e)])->withInput();
        }

        return back()->with('status', 'The variant was added. If the product is approved it needs approval again.');
    }

    public function variantArchive(Request $request, string $id, string $variant)
    {
        $this->need($request, 'catalog.manage');
        $p = $this->repo->find(self::id($id)) ?? abort(404);
        abort_unless(collect($p['variants'])->contains(fn ($v) => $v['id'] === self::id($variant)), 404);
        abort_if(count($p['variants']) <= 1, 422, 'There must be at least one variant.');
        $this->repo->archiveVariant($variant);

        return back()->with('status', 'The variant was archived.');
    }

    public function stock(Request $request, string $id, string $variant)
    {
        $this->need($request, 'catalog.manage');
        $p = $this->repo->find(self::id($id)) ?? abort(404);
        abort_unless(collect($p['variants'])->contains(fn ($v) => $v['id'] === self::id($variant)), 404);
        $d = $request->validate(['delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'], 'reason' => ['required', 'in:adjust,sale,return,correction'], 'note' => ['nullable', 'string', 'max:200']]);
        $r = $this->svc->adjustStock($variant, (int) $d['delta'], $d['reason'], $d['note'] ?? null, $this->uid($request));

        return back()->with($r['ok'] ? 'status' : 'error', $r['ok'] ? "Stock is now {$r['qty']}." : $r['error']);
    }

    public function aiWrite(Request $request, AiWriter $writer)
    {
        $this->need($request, 'catalog.manage');
        $d = $request->validate([
            'title' => ['required', 'string', 'max:250'], 'category' => ['nullable', 'string', 'max:120'], 'tags' => ['nullable', 'string', 'max:400'],
            'price' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:1500'], 'language' => ['nullable', 'in:bn,en'], 'tone' => ['nullable', 'string', 'max:80'],
        ]);
        try {
            $r = $writer->write($this->tenant->idOrFail(), $this->uid($request), [
                'title' => $d['title'], 'category' => $d['category'] ?? null, 'tags' => array_values(array_filter(array_map('trim', explode(',', (string) ($d['tags'] ?? ''))))),
                'price' => $d['price'] ?? null, 'notes' => $d['notes'] ?? null, 'language' => $d['language'] ?? 'bn', 'tone' => $d['tone'] ?? null,
            ]);
        } catch (AiWriterException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], in_array($e->errorCode, ['insufficient_credits', 'no_model'], true) ? 402 : 422);
        }

        return response()->json(['data' => $r]);
    }

    public function importForm(Request $request)
    {
        $this->need($request, 'catalog.manage');

        return view('catalog.import', ['imports' => $this->repo->imports(10), 'result' => session('import_result')]);
    }

    public function importStore(Request $request)
    {
        $this->need($request, 'catalog.manage');
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $file = $request->file('file');
        $csv = (string) file_get_contents($file->getRealPath());
        $res = $this->svc->import($this->tenant->idOrFail(), $this->uid($request), mb_substr((string) $file->getClientOriginalName(), 0, 200), $csv);

        return redirect()->route('catalog.import')->with('import_result', $res);
    }

    public function sample()
    {
        $csv = "sku,title,description,price,sale_price,currency,category,tags,images,stock,options,parent_sku\n"
            ."TEE-1,Cotton T-shirt,Soft cotton T-shirt,\"1,200\",999,BDT,Shirt,cotton|warm,https://example.com/tee.jpg,,,\n"
            ."TEE-1-M,,,,,,,,,10,Size=M;Color=Red,TEE-1\n"
            ."TEE-1-L,,,1300,,,,,,5,Size=L;Color=Red,TEE-1\n"
            ."MUG-1,Coffee mug,Ceramic mug,350,,BDT,kitchen,mug,,25,,\n";

        return response("\xEF\xBB\xBF".$csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="catalog-sample.csv"']);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->only(['title', 'description', 'price', 'sale_price', 'currency', 'sku', 'category', 'tags', 'images', 'seo_title', 'seo_description', 'ai_generated']);
    }

    /** @param array<string, mixed>|null $p @return array<string, mixed> */
    private function viewData(Request $request, ?array $p): array
    {
        $first = $p === null ? null : ($p['variants'][0]['id'] ?? null);

        return [
            'p' => $p, 'statusBn' => self::STATUS_BN,
            'events' => $p === null ? [] : $this->repo->events($p['id']),
            'canManage' => $request->user()->can('catalog.manage'), 'canApprove' => $request->user()->can('catalog.approve'),
            'firstVariant' => $first,
        ];
    }
}
