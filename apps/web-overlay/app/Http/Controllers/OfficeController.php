<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Office\OfficePrompt;
use App\Office\OfficeRepo;
use App\Office\OfficeService;
use App\Office\OfficeSpec;
use App\Office\OfficeText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** "Create": the assistant writes a document, spreadsheet or presentation; the person downloads it. Files are personal. */
final class OfficeController extends Controller
{
    public function __construct(private readonly OfficeService $service, private readonly OfficeRepo $repo, private readonly TenantContext $tenant) {}

    private static function id(string $id): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);

        return $id;
    }

    public function index(Request $request)
    {
        return view('office.index', ['files' => $this->repo->list((string) $request->user()->id), 'kinds' => OfficeSpec::KINDS, 'max' => OfficePrompt::MAX_REQUEST]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['kind' => ['required', 'in:docx,xlsx,pptx'], 'request' => ['required', 'string', 'max:'.OfficePrompt::MAX_REQUEST]]);
        $r = $this->service->create($this->tenant->idOrFail(), (string) $request->user()->id, $d['kind'], $d['request'], CreateWorkspace::newId(), CreateWorkspace::newId());
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['office' => OfficeText::create($r['status'])])->withInput();
        }

        return back()->with('status', "Created: {$r['title']} — Download from the list below.");
    }

    public function download(Request $request, string $id)
    {
        try {
            $f = $this->service->download($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));
        } catch (\RuntimeException $e) {
            report($e);

            return back()->withErrors(['office' => 'The file cannot be made right now. Try again shortly.']);
        }
        abort_if($f === null, 404);

        return response($f['bytes'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="download.'.pathinfo($f['name'], PATHINFO_EXTENSION).'"; filename*=UTF-8\'\''.rawurlencode($f['name']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, string $id)
    {
        $r = $this->repo->delete($this->tenant->idOrFail(), (string) $request->user()->id, self::id($id));

        return $r === 'ok' ? back()->with('status', 'Deleted.') : back()->withErrors(['office' => 'This is no longer found.']);
    }
}
