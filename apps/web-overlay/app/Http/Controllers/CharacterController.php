<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Character\Avatar;
use App\Character\CharacterRepo;
use App\Character\CharacterService;
use App\Character\Emotion;
use App\Character\Visemes;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** AI characters of the workspace: anyone can add one; only its creator edits or deletes it. */
final class CharacterController extends Controller
{
    public function __construct(private readonly CharacterService $service, private readonly CharacterRepo $repo, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        return view('characters.index', [
            'characters' => $this->repo->all($this->tenant->idOrFail()),
            'me' => (string) $request->user()->getAuthIdentifier(),
            'starters' => CharacterService::starters(),
            'max' => CharacterService::MAX,
        ]);
    }

    /** The avatar picture: only fixed words (expression, mouth) choose what is drawn.  Anyone in the workspace may see it. */
    public function avatar(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $c = $this->repo->find($this->tenant->idOrFail(), $id);
        abort_if($c === null, 404);

        return response(Avatar::render((string) $c['kind'], (string) $request->query('expression', 'neutral'), (string) $request->query('mouth', 'closed')), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Feeling + lip-sync track for a reply text (estimate; real audio length can replace `ms`). */
    public function cues(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        abort_if($this->repo->find($this->tenant->idOrFail(), $id) === null, 404);
        $d = $request->validate(['text' => ['required', 'string', 'max:4000'], 'ms' => ['nullable', 'integer', 'min:1', 'max:600000']]);
        $ms = (int) ($d['ms'] ?? Visemes::estimateMs($d['text']));

        return response()->json(['data' => ['expression' => Emotion::detect($d['text']), 'duration_ms' => $ms, 'timeline' => Visemes::timeline($d['text'], $ms)]]);
    }

    public function store(Request $request)
    {
        $r = $this->service->create($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $this->input($request));

        return $r['ok'] ? back()->with('status', 'The character was added.') : back()->withErrors(['character' => $r['error']])->withInput();
    }

    public function update(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $r = $this->service->update($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id, $this->input($request));

        return $r['ok'] ? back()->with('status', 'The character was updated.') : back()->withErrors(['character' => $r['error']])->withInput();
    }

    public function destroy(Request $request, string $id)
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $ok = $this->service->delete($this->tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $id);

        return $ok ? back()->with('status', 'The character was deleted.') : back()->withErrors(['character' => 'The character was not found, or it is not yours.']);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'], 'kind' => ['required', 'string', 'max:10'], 'personality' => ['required', 'string', 'max:5000'],
            'speaking_style' => ['nullable', 'string', 'max:5000'], 'language' => ['nullable', 'string', 'max:5'], 'voice' => ['nullable', 'string', 'max:200'],
            'avatar_style' => ['nullable', 'string', 'max:5'], 'greeting' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
