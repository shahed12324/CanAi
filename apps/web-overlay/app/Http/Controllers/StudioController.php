<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Studio\BrandSource;
use App\Studio\DesignRepo;
use App\Studio\ImagePricing;
use App\Studio\StudioService;
use App\Studio\StudioTemplates;
use App\Studio\StudioText;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The design studio: posters, posts, stories, covers and ad pictures with the workspace's brand, real Bengali text and an optional AI background.
 * Designs are personal.  A design can be sent to a client through a private approval link (see ReviewController for the client side).
 */
final class StudioController extends Controller
{
    private const CACHE_SECONDS = 3600;

    public function __construct(private readonly StudioService $studio, private readonly DesignRepo $designs, private readonly ImagePricing $pricing, private readonly TenantContext $tenant) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->id;
    }

    /** @return array{primary: string, accent: string, name: string, footer: string, logo_b64: ?string, has_kit: bool} */
    private function brand(): array
    {
        return app(BrandSource::class)->current();
    }

    public function index(Request $request)
    {
        return view('studio.index', ['designs' => $this->designs->list($this->uid($request)), 'brand' => $this->brand(), 'sizes' => StudioText::SIZES, 'themes' => StudioText::THEMES,
            'price' => $this->pricing->image(), 'rid' => CreateWorkspace::newId(),
            'templates' => StudioTemplates::all(), 'tpl' => StudioTemplates::get($request->query('t') === null ? null : (string) $request->query('t')), 'tplKey' => StudioTemplates::get((string) $request->query('t')) === null ? null : (string) $request->query('t')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'],
            'title' => ['required', 'string', 'max:120'], 'headline' => ['nullable', 'string', 'max:200'], 'subline' => ['nullable', 'string', 'max:400'], 'cta' => ['nullable', 'string', 'max:100'],
            'theme' => ['required', 'in:'.implode(',', StudioService::THEMES)], 'sizes' => ['required', 'array', 'min:1', 'max:5'], 'sizes.*' => ['string', 'in:'.implode(',', StudioService::SIZES)],
            'source' => ['required', 'in:none,ai,upload'], 'prompt' => ['nullable', 'string', 'max:1500'], 'brief' => ['nullable', 'string', 'max:1500'],
            'photo' => ['nullable', 'file', 'max:4000'],
        ]);
        $photo = null;
        if ($d['source'] === 'upload') {
            $f = $request->file('photo');
            $photo = $f !== null && $f->isValid() ? (string) file_get_contents($f->getRealPath()) : '';
        }
        $b = $this->brand();
        $r = $this->studio->create($this->tenant->idOrFail(), $this->uid($request), $d, $b, $d['source'], $photo, $d['rid']);
        if ($r['status'] !== 'ok') {
            $msg = StudioText::message($r['status']).($r['credits'] !== '0.0000' ? ' (For the image '.$r['credits'].' credits were deducted.)' : '');

            return back()->withErrors(['studio' => $msg])->withInput($request->except('photo'));
        }
        $note = $r['credits'] !== '0.0000' ? ' For the image '.$r['credits'].' credits were deducted.' : '';

        return redirect()->route('studio.show', $r['id'])->with('status', 'The design was created.'.$note);
    }

    public function logo(Request $request)
    {
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'idea' => ['required', 'string', 'min:3', 'max:400']]);
        $r = $this->studio->logo($this->tenant->idOrFail(), $this->uid($request), $d['idea'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['studio' => StudioText::message($r['status']).($r['credits'] !== '0.0000' ? ' (For the image '.$r['credits'].' credits were deducted.)' : '')])->withInput($request->only('idea'));
        }
        $bytes = (string) base64_decode((string) $r['image_b64'], true);
        $png = str_starts_with($bytes, "\x89PNG");
        abort_if($bytes === '' || (! $png && ! str_starts_with($bytes, "\xFF\xD8\xFF")), 502, StudioText::message('failed'));

        return response($bytes, 200, ['Content-Type' => $png ? 'image/png' : 'image/jpeg', 'Content-Disposition' => 'attachment; filename="logo-idea.'.($png ? 'png' : 'jpg').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(Request $request, string $id)
    {
        $d = $this->designs->find($this->uid($request), self::id($id));
        abort_if($d === null, 404);
        unset($d['base_b64'], $d['logo_b64']);       // the page never needs the pictures themselves

        return view('studio.show', ['d' => $d, 'sizes' => StudioText::SIZES, 'themes' => StudioText::THEMES, 'versions' => $this->designs->versions($this->uid($request), $d['id']), 'reviews' => $this->designs->reviews($this->uid($request), $d['id']), 'zip' => class_exists(\ZipArchive::class), 'link' => session('review_link')]);
    }

    /** @return array{mime: string, bytes: string}|null */
    private function drawn(Request $request, string $id, string $size, string $fmt): ?array
    {
        $uid = $this->uid($request);
        $design = $this->designs->find($uid, self::id($id));
        abort_if($design === null, 404);
        abort_unless(in_array($size, $design['sizes'], true), 404);
        $fmt = $fmt === 'jpeg' ? 'jpeg' : 'png';
        $tenant = $this->tenant->idOrFail();
        $hit = Cache::remember("studio:{$tenant}:{$design['id']}:{$size}:{$fmt}", self::CACHE_SECONDS, function () use ($tenant, $uid, $design, $size, $fmt) {
            $r = $this->studio->render($tenant, $uid, $design, [$size], $fmt);

            return isset($r['items'][$size]) ? ['mime' => $r['items'][$size]['mime'], 'b64' => base64_encode($r['items'][$size]['bytes'])] : null;
        });
        if ($hit === null) {
            Cache::forget("studio:{$tenant}:{$design['id']}:{$size}:{$fmt}");

            return null;
        }

        return ['mime' => $hit['mime'], 'bytes' => (string) base64_decode($hit['b64'], true)];
    }

    public function image(Request $request, string $id, string $size)
    {
        $img = $this->drawn($request, $id, $size, 'png');
        abort_if($img === null, 502, StudioText::message('engine'));

        return response($img['bytes'], 200, ['Content-Type' => $img['mime'], 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(Request $request, string $id, string $size)
    {
        $fmt = $request->query('fmt') === 'jpeg' ? 'jpeg' : 'png';
        $img = $this->drawn($request, $id, $size, $fmt);
        abort_if($img === null, 502, StudioText::message('engine'));

        return response($img['bytes'], 200, ['Content-Type' => $img['mime'], 'Content-Disposition' => 'attachment; filename="design-'.$size.'.'.($fmt === 'jpeg' ? 'jpg' : 'png').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function zip(Request $request, string $id)
    {
        abort_unless(class_exists(\ZipArchive::class), 404);
        $uid = $this->uid($request);
        $design = $this->designs->find($uid, self::id($id));
        abort_if($design === null, 404);
        $fmt = $request->query('fmt') === 'jpeg' ? 'jpeg' : 'png';
        $r = $this->studio->render($this->tenant->idOrFail(), $uid, $design, $design['sizes'], $fmt);
        abort_if($r['status'] !== 'ok', 502, StudioText::message('engine'));
        $tmp = tempnam(sys_get_temp_dir(), 'studio');
        $z = new \ZipArchive;
        abort_unless($z->open($tmp, \ZipArchive::OVERWRITE) === true, 500);
        foreach ($r['items'] as $size => $it) {
            $z->addFromString("design-{$size}.".($fmt === 'jpeg' ? 'jpg' : 'png'), $it['bytes']);
        }
        $z->close();

        return response()->download($tmp, 'design-'.$design['id'].'.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    /** Pictures drawn from the old words must not be shown any more. */
    private function forgetDrawn(string $id): void
    {
        $tenant = $this->tenant->idOrFail();
        foreach (StudioService::SIZES as $size) {
            foreach (['png', 'jpeg'] as $fmt) {
                Cache::forget("studio:{$tenant}:{$id}:{$size}:{$fmt}");
            }
        }
    }

    public function edit(Request $request, string $id)
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'headline' => ['nullable', 'string', 'max:200'], 'subline' => ['nullable', 'string', 'max:400'], 'cta' => ['nullable', 'string', 'max:100'],
            'theme' => ['required', 'in:'.implode(',', StudioService::THEMES)], 'sizes' => ['required', 'array', 'min:1', 'max:5'], 'sizes.*' => ['string', 'in:'.implode(',', StudioService::SIZES)],
        ]);
        $r = $this->studio->edit($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d);
        if ($r === 'ok') {
            $this->forgetDrawn($id);

            return back()->with('status', StudioText::message('saved'));
        }

        return $r === 'unchanged' ? back()->with('status', StudioText::message('unchanged')) : back()->withErrors(['studio' => StudioText::message($r)])->withInput();
    }

    public function restore(Request $request, string $id, int $n)
    {
        $r = $this->studio->restore($this->tenant->idOrFail(), $this->uid($request), self::id($id), $n);
        if ($r === 'ok') {
            $this->forgetDrawn($id);

            return back()->with('status', StudioText::message('restored'));
        }

        return back()->withErrors(['studio' => StudioText::message('not_found')]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->designs->delete($this->tenant->idOrFail(), $this->uid($request), self::id($id));

        return redirect()->route('studio.index')->with('status', 'The design was deleted.');
    }

    public function share(Request $request, string $id)
    {
        $d = $request->validate(['size' => ['required', 'string', 'max:10'], 'client' => ['nullable', 'string', 'max:80'], 'days' => ['required', 'integer', 'min:1', 'max:30']]);
        $r = $this->studio->share($this->tenant->idOrFail(), $this->uid($request), self::id($id), $d['size'], (string) ($d['client'] ?? ''), (int) $d['days']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['studio' => StudioText::message((string) $r['status'])]);
        }

        // The link is shown once: only its hash is kept, so it cannot be shown again.
        return back()->with('review_link', route('review.show', ['token' => $r['token']]))->with('status', 'The client’s link was created — copy it now; it cannot be shown later.');
    }

    public function revoke(Request $request, string $id, string $review)
    {
        $this->designs->revokeReview($this->tenant->idOrFail(), $this->uid($request), self::id($review));

        return back()->with('status', 'The link was disabled.');
    }
}
