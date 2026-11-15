<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Studio\BrandSource;
use App\Studio\StudioService;
use App\Studio\StudioText;
use App\Tenancy\TenantContext;
use App\Video\VideoFiles;
use App\Video\VideoPricing;
use App\Video\VideoRepo;
use App\Video\VideoScriptService;
use App\Video\VideoService;
use App\Video\VideoText;
use App\Voice\VoiceText;
use Illuminate\Http\Request;

/** Slide videos made from the brand kit: a few scenes of real (Bengali-correct) text over a colour or photo, with optional AI voice-over.  Videos are personal. */
final class VideoController extends Controller
{
    public function __construct(
        private readonly VideoService $video,
        private readonly VideoScriptService $scripts,
        private readonly VideoRepo $repo,
        private readonly VideoFiles $files,
        private readonly VideoPricing $pricing,
        private readonly \App\Studio\ImagePricing $imagePricing,
        private readonly BrandSource $brand,
        private readonly TenantContext $tenant,
    ) {}

    private static function id(string $v): string
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $v) === 1, 404);

        return $v;
    }

    private function uid(Request $r): string
    {
        return (string) $r->user()->id;
    }

    public function index(Request $request)
    {
        return view('video.index', ['jobs' => $this->repo->list($this->uid($request)), 'brand' => $this->brand->current(), 'sizes' => ['post' => StudioText::SIZES['post'], 'portrait' => StudioText::SIZES['portrait'],
            'story' => StudioText::SIZES['story'], 'ad' => StudioText::SIZES['ad']], 'themes' => StudioText::THEMES, 'voices' => VoiceText::VOICES, 'music' => VideoService::MUSIC, 'imagePrice' => $this->imagePricing->image(), 'perSecond' => $this->pricing->forSeconds(1.0),
            'rid' => CreateWorkspace::newId(), 'status' => VideoText::STATUS]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'],
            'title' => ['required', 'string', 'max:120'], 'size' => ['required', 'in:'.implode(',', VideoService::SIZES)],
            'scenes' => ['required', 'array', 'min:1', 'max:'.VideoService::MAX_SCENES],
            'scenes.*.headline' => ['nullable', 'string', 'max:200'], 'scenes.*.subline' => ['nullable', 'string', 'max:400'], 'scenes.*.cta' => ['nullable', 'string', 'max:100'],
            'scenes.*.theme' => ['required', 'in:'.implode(',', StudioService::THEMES)], 'scenes.*.seconds' => ['required', 'numeric', 'min:2', 'max:8'],
            'scenes.*.photo' => ['nullable', 'file', 'max:4000'], 'scenes.*.ai_prompt' => ['nullable', 'string', 'max:500'],
            'narration' => ['nullable', 'string', 'max:2000'], 'captions' => ['nullable', 'boolean'], 'music' => ['nullable', 'in:'.implode(',', array_keys(VideoService::MUSIC))], 'voice' => ['nullable', 'in:'.implode(',', array_keys(VoiceText::VOICES))],
        ]);
        $scenes = [];
        foreach ($d['scenes'] as $k => $s) {
            $f = $request->file("scenes.{$k}.photo");
            $s['photo'] = $f !== null && $f->isValid() ? (string) file_get_contents($f->getRealPath()) : null;
            $scenes[] = $s;
        }
        $in = ['title' => $d['title'], 'size' => $d['size'], 'scenes' => $scenes, 'narration' => $d['narration'] ?? '', 'captions' => (bool) ($d['captions'] ?? false), 'music' => (string) ($d['music'] ?? ''), 'voice' => $d['voice'] ?? 'nova'];
        $r = $this->video->create($this->tenant->idOrFail(), $this->uid($request), $in, $this->brand->current(), $d['rid']);
        if ($r['status'] !== 'ok') {
            $msg = VideoText::message($r['status']).($r['credits'] !== '0.0000' ? ' (For the voice '.$r['credits'].' credits were deducted.)' : '');

            return back()->withErrors(['video' => $msg])->withInput($request->except('scenes.*.photo'));
        }

        return redirect()->route('video.show', $r['id'])->with('status', 'The video was queued — it will be made in a minute or two.');
    }

    public function clip(Request $request)
    {
        $d = $request->validate([
            'rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'title' => ['required', 'string', 'max:120'], 'size' => ['required', 'in:'.implode(',', VideoService::SIZES)],
            'video' => ['required', 'file', 'max:25000'], 'trim_start' => ['nullable', 'numeric', 'min:0', 'max:600'], 'trim_end' => ['nullable', 'numeric', 'min:0', 'max:600'], 'remove_silence' => ['nullable', 'boolean'],
        ]);
        $f = $request->file('video');
        $bytes = $f !== null && $f->isValid() ? (string) file_get_contents($f->getRealPath()) : '';
        $r = $this->video->createClip($this->tenant->idOrFail(), $this->uid($request), ['title' => $d['title'], 'size' => $d['size'], 'trim_start' => (float) ($d['trim_start'] ?? 0),
            'trim_end' => isset($d['trim_end']) && $d['trim_end'] !== null && $d['trim_end'] !== '' ? (float) $d['trim_end'] : null, 'remove_silence' => (bool) ($d['remove_silence'] ?? false)], $bytes, $d['rid']);
        if ($r['status'] !== 'ok') {
            return back()->withErrors(['video' => VideoText::message($r['status'])])->withInput($request->except('video'));
        }

        return redirect()->route('video.show', $r['id'])->with('status', 'The clip was queued — it will show here when made.');
    }

    /** Writes scenes and a voice-over from a short brief; the page fills the form with it for the person to edit.  Costs a little text credit. */
    public function script(Request $request)
    {
        $d = $request->validate(['rid' => ['required', 'regex:/\A[0-9A-Za-z]{26}\z/'], 'brief' => ['required', 'string', 'max:1500'], 'scenes' => ['required', 'integer', 'min:1', 'max:10']]);
        $r = $this->scripts->write($this->tenant->idOrFail(), $this->uid($request), $d['brief'], (int) $d['scenes'], $d['rid']);
        if ($r['status'] !== 'ok') {
            return response()->json(['ok' => false, 'message' => VideoText::script($r['status'])], 422);
        }

        return response()->json(['ok' => true, 'script' => $r['script']]);
    }

    public function show(Request $request, string $id)
    {
        $j = $this->repo->find($this->uid($request), self::id($id));
        abort_if($j === null, 404);

        return view('video.show', ['j' => $j, 'status' => VideoText::STATUS]);
    }

    public function file(Request $request, string $id)
    {
        $j = $this->repo->find($this->uid($request), self::id($id));
        abort_if($j === null || $j['status'] !== 'done' || $j['file_path'] === null, 404);
        $path = $this->files->path($this->tenant->idOrFail(), $j['file_path']);
        abort_if($path === null, 404);
        $name = 'video-'.$j['size_key'].'-'.substr($j['id'], 0, 8).'.mp4';
        $disp = $request->query('dl') === '1' ? 'attachment' : 'inline';

        return response()->file($path, ['Content-Type' => 'video/mp4', 'Content-Disposition' => $disp.'; filename="'.$name.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function cancel(Request $request, string $id)
    {
        $r = $this->video->cancel($this->tenant->idOrFail(), $this->uid($request), self::id($id));

        return back()->with('status', $r === 'ok' ? 'Cancelled; credits were refunded.' : 'It can no longer be cancelled (making has started or finished).');
    }

    public function destroy(Request $request, string $id)
    {
        $this->video->delete($this->tenant->idOrFail(), $this->uid($request), self::id($id));

        return redirect()->route('video.index')->with('status', 'The video was deleted.');
    }
}
