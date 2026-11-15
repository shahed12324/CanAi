<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use App\Voice\AudioInput;
use App\Voice\VoiceService;
use App\Voice\VoiceText;
use Illuminate\Http\Request;

/** The voice page: speak to Jarvis in the browser (microphone), read the transcript and the short answer, and hear it back. Recordings are never stored. */
final class VoiceController extends Controller
{
    public function __construct(private readonly VoiceService $service, private readonly TenantContext $tenant) {}

    public function index(Request $request)
    {
        return view('voice.index', ['voices' => VoiceText::VOICES, 'languages' => VoiceText::LANGUAGES, 'maxBytes' => AudioInput::MAX_BYTES, 'avatar' => $this->avatar($request)]);
    }

    /** The companion's 2-D avatar if there is one (the person's chosen character, else the first 2-D one).  Never breaks the page. */
    private function avatar(Request $request): ?array
    {
        try {
            $tenant = $this->tenant->idOrFail();
            $repo = app(\App\Character\CharacterRepo::class);
            $chosen = app(\App\Companion\ModeRepo::class)->get($tenant, (string) $request->user()->getAuthIdentifier())['character_id'];
            $c = $chosen !== null ? $repo->find($tenant, $chosen) : null;
            if ($c === null || ($c['avatar_style'] ?? 'none') !== '2d') {
                $c = collect($repo->all($tenant))->first(fn ($x) => ($x['avatar_style'] ?? 'none') === '2d');
            }

            return $c === null ? null : ['name' => (string) $c['name'], 'src' => route('characters.avatar', trim((string) $c['id']))];
        } catch (\Throwable) {
            return null;
        }
    }

    private function audio(Request $r): ?string
    {
        $f = $r->file('audio');
        if ($f === null || ! $f->isValid() || $f->getSize() > AudioInput::MAX_BYTES) {
            return $f === null ? null : '';
        }

        return (string) file_get_contents($f->getRealPath());
    }

    public function ask(Request $request)
    {
        $d = $request->validate(['text' => ['nullable', 'string', 'max:2000'], 'lang' => ['nullable', 'string', 'max:3'], 'voice' => ['nullable', 'string', 'max:20'], 'speak' => ['nullable', 'in:0,1'], 'audio' => ['nullable', 'file', 'max:5200']]);
        $r = $this->service->ask($this->tenant->idOrFail(), (string) $request->user()->id, $this->audio($request), (string) ($d['text'] ?? ''), $d['lang'] ?? null,
            ($d['speak'] ?? '0') === '1', $d['voice'] ?? 'alloy', CreateWorkspace::newId());

        return response()->json($r + ['message' => $r['status'] === 'ok' ? null : VoiceText::message($r['status'])], $r['status'] === 'ok' ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    public function listen(Request $request)
    {
        $request->validate(['lang' => ['nullable', 'string', 'max:3'], 'audio' => ['required', 'file', 'max:5200']]);
        $r = $this->service->listen($this->tenant->idOrFail(), (string) $request->user()->id, (string) $this->audio($request), $request->input('lang'), CreateWorkspace::newId());

        return response()->json($r + ['message' => $r['status'] === 'ok' ? null : VoiceText::message($r['status'])], $r['status'] === 'ok' ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    public function speak(Request $request)
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:2000'], 'voice' => ['nullable', 'string', 'max:20']]);
        $r = $this->service->speak($this->tenant->idOrFail(), (string) $request->user()->id, $d['text'], $d['voice'] ?? 'alloy', CreateWorkspace::newId());

        return response()->json($r + ['message' => $r['status'] === 'ok' ? null : VoiceText::message($r['status'])], $r['status'] === 'ok' ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }
}
