<?php

declare(strict_types=1);

namespace App\Video;

use App\Actions\Tenancy\CreateWorkspace;
use App\Credits\CreditException;
use App\Credits\CreditLedger;
use App\Credits\CreditMath;
use App\Studio\ImagePicker;
use App\Studio\ImagePricing;
use App\Studio\StudioEngine;
use App\Studio\StudioService;
use App\Voice\VoiceText;

/**
 * Slide videos.  Money invariant: the render amount (price per second x length, known up front) is held when the job is created and ends in exactly one
 * settle (the video was made) or one release (it was not, or the job was cancelled / could not be saved).  A voice-over is a separate speech charge made by the Narrator.
 * The slow work happens later in work(), run by the minute-by-minute worker, never inside a web request.
 */
final class VideoService
{
    public const SIZES = ['post', 'portrait', 'story', 'ad'];

    public const MAX_SCENES = 10;

    public const MUSIC = ['calm' => 'Calm', 'warm' => 'Warm', 'upbeat' => 'Lively', 'cinematic' => 'Serious (cinematic)'];

    public const MAX_TOTAL = 60;

    public const MAX_AI_SCENES = 5;

    public const MAX_CLIP_BYTES = 25_000_000;

    public function __construct(
        private readonly VideoEngine $engine,
        private readonly VideoRepo $repo,
        private readonly VideoFiles $files,
        private readonly Narrator $narrator,
        private readonly CreditLedger $credits,
        private readonly VideoPricing $pricing,
        private readonly StudioEngine $images,
        private readonly ImagePicker $picker,
        private readonly ImagePricing $imagePricing,
    ) {}

    /**
     * @param  array<string, mixed>  $in  title, size, scenes[{headline,subline,cta,theme,seconds,photo(bytes|null)}], narration, voice
     * @param  array{primary: string, accent: string, name: string, footer: string, logo_b64: ?string}  $brand
     * @return array{status: string, id: ?string, credits: string}
     */
    public function create(string $tenantId, string $userId, array $in, array $brand, string $requestId): array
    {
        $fail = fn (string $s, string $c = '0.0000') => ['status' => $s, 'id' => null, 'credits' => $c];
        $title = StudioService::line((string) ($in['title'] ?? ''), 120);
        $size = (string) ($in['size'] ?? '');
        $raw = $in['scenes'] ?? null;
        $narration = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($in['narration'] ?? '')));
        $voice = (string) ($in['voice'] ?? 'nova');
        $captions = ! empty($in['captions']);
        $music = (string) ($in['music'] ?? '');
        if ($title === '') {
            return $fail('bad_title');
        }
        if (! in_array($size, self::SIZES, true)) {
            return $fail('bad_size');
        }
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_SCENES) {
            return $fail('bad_scenes');
        }
        if (mb_strlen($narration) > 2000) {
            return $fail('bad_narration');
        }
        if ($narration !== '' && ! isset(VoiceText::VOICES[$voice])) {
            return $fail('bad_voice');
        }
        if ($music !== '' && ! isset(self::MUSIC[$music])) {
            return $fail('bad_music');
        }
        if ($captions && $narration === '') {
            return $fail('bad_captions');
        }
        if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $brand['primary']) !== 1 || preg_match('/\A#[0-9a-fA-F]{6}\z/', $brand['accent']) !== 1) {
            return $fail('bad_brand');
        }
        $scenes = [];
        $photos = [];
        $total = 0.0;
        $aiCount = 0;
        foreach (array_values($raw) as $i => $s) {
            if (! is_array($s)) {
                return $fail('bad_scenes');
            }
            $h = StudioService::line((string) ($s['headline'] ?? ''), 10000);
            $sub = StudioService::line((string) ($s['subline'] ?? ''), 10000);
            $cta = StudioService::line((string) ($s['cta'] ?? ''), 10000);
            if (mb_strlen($h) > 200 || mb_strlen($sub) > 400 || mb_strlen($cta) > 100) {
                return $fail('bad_text');
            }
            if ($h === '' && $sub === '' && $cta === '') {
                return $fail('bad_scenes');
            }
            $theme = (string) ($s['theme'] ?? 'dark');
            if (! in_array($theme, StudioService::THEMES, true)) {
                return $fail('bad_theme');
            }
            $sec = round((float) ($s['seconds'] ?? 0), 1);
            if ($sec < 2.0 || $sec > 8.0) {
                return $fail('bad_seconds');
            }
            $has = false;
            $prompt = StudioService::line((string) ($s['ai_prompt'] ?? ''), 500);
            if (isset($s['photo']) && is_string($s['photo']) && $s['photo'] !== '') {
                $p = StudioService::photo($s['photo']);
                if ($p['status'] !== 'ok') {
                    return $fail('bad_photo');
                }
                $photos[$i] = (string) base64_decode((string) $p['base64'], true);
                $has = true;
            }
            if ($prompt !== '' && ! $has) {
                if (mb_strlen($prompt) < 3) {
                    return $fail('bad_prompt');
                }
                $aiCount++;
            } else {
                $prompt = '';   // an uploaded photo wins: nothing is generated or charged for this scene
            }
            $scenes[] = ['headline' => $h, 'subline' => $sub, 'cta' => $cta, 'theme' => $theme, 'seconds' => $sec, 'photo' => $has, 'ai_prompt' => $prompt];
            $total += $sec;
        }
        if ($total > self::MAX_TOTAL) {
            return $fail('too_long');
        }
        if ($this->repo->activeCount($userId) >= 3) {
            return $fail('limit');
        }
        $amount = $this->pricing->forSeconds($total);
        if ($aiCount > 0) {
            if ($aiCount > self::MAX_AI_SCENES) {
                return $fail('too_many_ai');
            }
            $model = $this->picker->pick($tenantId);
            if ($model === null) {
                return $fail('no_image_model');
            }
            $amount = CreditMath::fromUnits(CreditMath::toUnits($amount) + $aiCount * CreditMath::toUnits($this->imagePricing->image(1, $model['credit_multiplier'] ?? null)));
        }
        $id = CreateWorkspace::newId();
        try {
            $reservation = $this->credits->reserve($tenantId, $amount, 'video:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        $spoken = '0.0000';
        $stored = [];
        try {
            $audioName = null;
            if ($narration !== '') {
                $v = $this->narrator->speak($tenantId, $userId, $narration, $voice, $requestId.'-v');
                $spoken = $v['credits'];
                $audio = $v['status'] === 'ok' && is_string($v['audio_b64']) ? base64_decode($v['audio_b64'], true) : false;
                if ($v['status'] !== 'ok' || ! is_string($audio) || ! $this->isMp3($audio)) {
                    $this->releaseQuietly($tenantId, $reservation, $requestId);

                    return $fail($v['status'] === 'ok' ? 'failed' : $v['status'], $spoken);
                }
                $audioName = $id.'-voice.mp3';
                $this->files->put($tenantId, $audioName, $audio);
                $stored[] = $audioName;
            }
            foreach ($photos as $i => $bytes) {
                $name = $id.'-'.$i.'.img';
                $this->files->put($tenantId, $name, $bytes);
                $stored[] = $name;
            }
            $meta = ['primary' => strtolower($brand['primary']), 'accent' => strtolower($brand['accent']), 'name' => StudioService::line($brand['name'], 100), 'footer' => StudioService::line($brand['footer'], 240), 'captions' => $captions, 'music' => $music];
            $r = $this->repo->create($tenantId, $userId, $id, $title, $size, $scenes, $narration === '' ? null : $narration, $narration === '' ? null : $voice, $meta, $brand['logo_b64'] ?? null,
                $audioName, $reservation, $amount, number_format($total, 1, '.', ''));
            if ($r !== 'ok') {
                $this->cleanup($tenantId, $stored);
                $this->releaseQuietly($tenantId, $reservation, $requestId);

                return $fail($r === 'limit' ? 'limit' : 'failed', $spoken);
            }
        } catch (\Throwable $e) {
            self::report($e);
            $this->cleanup($tenantId, $stored);
            $this->releaseQuietly($tenantId, $reservation, $requestId);

            return $fail('failed', $spoken);
        }

        return ['status' => 'ok', 'id' => $id, 'credits' => $spoken];
    }

    /**
     * A clip made from the person's own video: trimmed, optionally with the silent parts cut out, fitted to a size.
     * The price is per second of the trimmed window (cutting silences does not lower it); it is held up front and settled when the clip is made.
     *
     * @param  array{title?: string, size?: string, trim_start?: float|int, trim_end?: float|int|null, remove_silence?: bool}  $in
     * @return array{status: string, id: ?string, credits: string}
     */
    public function createClip(string $tenantId, string $userId, array $in, string $bytes, string $requestId): array
    {
        $fail = fn (string $s) => ['status' => $s, 'id' => null, 'credits' => '0.0000'];
        $title = StudioService::line((string) ($in['title'] ?? ''), 120);
        $size = (string) ($in['size'] ?? '');
        $start = round(max(0.0, (float) ($in['trim_start'] ?? 0)), 1);
        $end = isset($in['trim_end']) && $in['trim_end'] !== null ? round((float) $in['trim_end'], 1) : null;
        $silence = ! empty($in['remove_silence']);
        if ($title === '') {
            return $fail('bad_title');
        }
        if (! in_array($size, self::SIZES, true)) {
            return $fail('bad_size');
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_CLIP_BYTES) {
            return $fail('bad_video');
        }
        if ($end !== null && $end <= $start) {
            return $fail('bad_trim');
        }
        if ($this->repo->activeCount($userId) >= 3) {
            return $fail('limit');
        }
        try {
            $info = $this->engine->probe(base64_encode($bytes), $tenantId, $userId);
        } catch (\Throwable $e) {
            if (! $e instanceof \App\Ai\ProviderCallException) {
                self::report($e);
            }

            return $fail('engine');
        }
        if ($info['status'] !== 'ok') {
            return $fail(match ($info['reason']) {
                'bad_video', 'bad_encoding' => 'bad_video', 'too_long' => 'clip_long', 'no_ffmpeg' => 'engine', default => 'failed',
            });
        }
        $window = floor((min($info['duration'], $end ?? $info['duration']) - $start) * 10) / 10;
        if ($window < 2.0) {
            return $fail('bad_trim');
        }
        if ($window > self::MAX_TOTAL) {
            return $fail('too_long');
        }
        $amount = $this->pricing->forSeconds($window);
        $id = CreateWorkspace::newId();
        try {
            $reservation = $this->credits->reserve($tenantId, $amount, 'video:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        $stored = [];
        try {
            $name = $id.'-clip.vid';
            $this->files->put($tenantId, $name, $bytes);
            $stored[] = $name;
            $scenes = [['clip' => true, 'headline' => $title, 'subline' => '', 'cta' => '', 'theme' => 'dark', 'seconds' => $window, 'photo' => false,
                'trim_start' => $start, 'trim_end' => $end, 'remove_silence' => $silence && $info['has_audio']]];
            $r = $this->repo->create($tenantId, $userId, $id, $title, $size, $scenes, null, null, ['clip' => true], null, null, $reservation, $amount, number_format($window, 1, '.', ''));
            if ($r !== 'ok') {
                $this->cleanup($tenantId, $stored);
                $this->releaseQuietly($tenantId, $reservation, $requestId);

                return $fail($r === 'limit' ? 'limit' : 'failed');
            }
        } catch (\Throwable $e) {
            self::report($e);
            $this->cleanup($tenantId, $stored);
            $this->releaseQuietly($tenantId, $reservation, $requestId);

            return $fail('failed');
        }

        return ['status' => 'ok', 'id' => $id, 'credits' => '0.0000'];
    }

    /** Renders one claimed job (status 'rendering').  Called by the worker inside the job's workspace. */
    public function work(string $tenantId, string $jobId): void
    {
        $job = $this->repo->get($jobId);
        if ($job === null || $job['status'] !== 'rendering') {
            return;
        }
        $sources = $this->sourceNames($job);
        try {
            [$drawn, $charge] = $this->drawScenes($tenantId, $job);
            $payload = $this->payload($tenantId, $job, $drawn);
            if ($payload === null) {
                $this->failJob($tenantId, $job, 'job_gone');

                return;
            }
            $res = null;
            $why = 'job_engine';
            $clip = ! empty($job['scenes'][0]['clip']);
            try {
                $res = $clip ? $this->engine->edit($payload, $tenantId, $job['user_id']) : $this->engine->render($payload, $tenantId, $job['user_id']);
            } catch (\App\Ai\ProviderCallException) {
                $why = 'job_busy';
            } catch (\Throwable $e) {
                self::report($e);
            }
            if ($res === null) {
                $this->failJob($tenantId, $job, $why);

                return;
            }
            if ($res['status'] !== 'ok' || ! is_string($res['bytes'])) {
                $this->failJob($tenantId, $job, match ($res['reason'] ?? '') {
                    'all_silent' => 'job_silent', 'bad_trim' => 'job_trim', 'bad_video' => 'job_badvideo', default => 'job_refused',
                });

                return;
            }
            $name = $jobId.'.mp4';
            try {
                $this->files->put($tenantId, $name, $res['bytes']);
            } catch (\Throwable $e) {
                self::report($e);
                $this->failJob($tenantId, $job, 'job_store');

                return;
            }
            $charged = $charge;
            try {
                $charged = (string) ($this->credits->settle($tenantId, (string) $job['reservation_id'], $charge, 'video-settle:'.$jobId, [
                    'source' => 'video', 'user_id' => $job['user_id'], 'request_id' => $jobId, 'input_tokens' => 0, 'output_tokens' => 0,
                ])['charged'] ?? $job['amount']);
            } catch (\Throwable $e) {
                self::report($e);   // the hold is returned by credit_release_stale()
            }
            try {
                $this->repo->done($tenantId, $jobId, $name, strlen($res['bytes']), $charged);
            } catch (\Throwable $e) {
                self::report($e);
                $this->files->delete($tenantId, $name);
            }
        } finally {
            $this->cleanup($tenantId, $sources);
        }
    }

    public function cancel(string $tenantId, string $userId, string $id): string
    {
        $job = $this->repo->find($userId, $id);
        if ($job === null) {
            return 'not_found';
        }
        if ($this->repo->cancel($tenantId, $userId, $id) !== 'ok') {
            return 'not_found';
        }
        if ($job['reservation_id'] !== null) {
            $this->releaseQuietly($tenantId, $job['reservation_id'], $id);
        }
        $this->cleanup($tenantId, $this->sourceNames($job));

        return 'ok';
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        $job = $this->repo->find($userId, $id);
        if ($job === null) {
            return 'not_found';
        }
        if ($this->repo->delete($tenantId, $userId, $id) !== 'ok') {
            return 'not_found';
        }
        $this->cleanup($tenantId, array_merge($this->sourceNames($job), [$id.'.mp4']));

        return 'ok';
    }

    /** @param  array<string, mixed>  $job @return ?array<string, mixed> */
    private function payload(string $tenantId, array $job, array $drawn = []): ?array
    {
        if (! empty($job['scenes'][0]['clip'])) {
            $c = $job['scenes'][0];
            $bytes = $this->files->get($tenantId, $job['id'].'-clip.vid');

            return $bytes === null ? null : ['video_b64' => base64_encode($bytes), 'trim_start' => (float) $c['trim_start'], 'trim_end' => $c['trim_end'] === null ? null : (float) $c['trim_end'],
                'remove_silence' => ! empty($c['remove_silence']), 'size' => $job['size_key']];
        }
        $scenes = [];
        foreach ($job['scenes'] as $i => $s) {
            $one = ['copy' => ['headline' => (string) $s['headline'], 'subline' => (string) $s['subline'], 'cta' => (string) $s['cta'], 'theme' => (string) $s['theme']], 'seconds' => (float) $s['seconds']];
            if (! empty($s['photo'])) {
                $bytes = $this->files->get($tenantId, $job['id'].'-'.$i.'.img');
                if ($bytes === null) {
                    return null;
                }
                $one['base_b64'] = base64_encode($bytes);
            } elseif (isset($drawn[$i])) {
                $one['base_b64'] = $drawn[$i];
            }
            $scenes[] = $one;
        }
        $b = $job['brand'];
        $out = ['size' => $job['size_key'], 'scenes' => $scenes, 'fade' => true, 'motion' => true,
            'brand' => ['primary' => (string) ($b['primary'] ?? '#1f3a8a'), 'accent' => (string) ($b['accent'] ?? '#f59e0b'), 'name' => (string) ($b['name'] ?? ''), 'footer' => (string) ($b['footer'] ?? ''), 'logo_b64' => $job['logo_b64']]];
        if (isset($b['music']) && is_string($b['music']) && isset(self::MUSIC[$b['music']])) {
            $out['music'] = $b['music'];
        }
        if (! empty($b['captions']) && is_string($job['narration']) && $job['narration'] !== '') {
            $out['captions'] = VideoCaptions::chunks($job['narration']);
        }
        if ($job['audio_path'] !== null) {
            $audio = $this->files->get($tenantId, $job['audio_path']);
            if ($audio === null) {
                return null;
            }
            $out['audio_b64'] = base64_encode($audio);
        }

        return $out;
    }

    /**
     * Asks the picture model for the scenes that have an AI prompt.  A scene whose picture does not come back is simply drawn on the brand colours, and is not charged.
     *
     * @param  array<string, mixed>  $job
     * @return array{0: array<int, string>, 1: string} [index => base64 picture, the amount to charge: render price + one picture price for each picture that arrived]
     */
    private function drawScenes(string $tenantId, array $job): array
    {
        $want = [];
        foreach ($job['scenes'] as $i => $s) {
            if (empty($s['clip']) && empty($s['photo']) && ! empty($s['ai_prompt'])) {
                $want[$i] = (string) $s['ai_prompt'];
            }
        }
        if ($want === []) {
            return [[], $job['amount']];
        }
        $render = CreditMath::toUnits($this->pricing->forSeconds((float) $job['seconds']));
        $unit = intdiv(max(0, CreditMath::toUnits($job['amount']) - $render), count($want));
        $model = null;
        try {
            $model = $this->picker->pick($tenantId);
        } catch (\Throwable $e) {
            self::report($e);
        }
        $drawn = [];
        if ($model !== null) {
            foreach ($want as $i => $prompt) {
                try {
                    $r = $this->images->image($model['provider_id'], $model['model_key'], $prompt, StudioService::GEN_SIZE[$job['size_key']] ?? '1024x1024', $tenantId, $job['user_id']);
                    if (($r['status'] ?? '') === 'ok' && is_string($r['image_b64'] ?? null) && $r['image_b64'] !== '') {
                        $drawn[$i] = $r['image_b64'];
                    }
                } catch (\Throwable $e) {
                    if (! $e instanceof \App\Ai\ProviderCallException) {
                        self::report($e);
                    }
                }
            }
        }

        return [$drawn, CreditMath::fromUnits($render + $unit * count($drawn))];
    }

    /** @param  array<string, mixed>  $job @return list<string> */
    private function sourceNames(array $job): array
    {
        if (! empty($job['scenes'][0]['clip'])) {
            return [$job['id'].'-clip.vid'];
        }
        $n = [];
        foreach ($job['scenes'] as $i => $s) {
            if (! empty($s['photo'])) {
                $n[] = $job['id'].'-'.$i.'.img';
            }
        }
        if ($job['audio_path'] !== null) {
            $n[] = $job['audio_path'];
        }

        return $n;
    }

    /** @param  array<string, mixed>  $job */
    private function failJob(string $tenantId, array $job, string $why): void
    {
        if ($job['reservation_id'] !== null) {
            $this->releaseQuietly($tenantId, (string) $job['reservation_id'], $job['id']);
        }
        try {
            $this->repo->fail($tenantId, $job['id'], VideoText::message($why));
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    private function isMp3(string $b): bool
    {
        return strlen($b) > 3 && (str_starts_with($b, 'ID3') || (ord($b[0]) === 0xFF && (ord($b[1]) & 0xE0) === 0xE0));
    }

    /** @param  list<string>  $names */
    private function cleanup(string $tenantId, array $names): void
    {
        foreach ($names as $n) {
            try {
                $this->files->delete($tenantId, $n);
            } catch (\Throwable $e) {
                self::report($e);
            }
        }
    }

    private function releaseQuietly(string $tenantId, string $reservation, string $key): void
    {
        try {
            $this->credits->release($tenantId, $reservation, 'video-release:'.$key);
        } catch (\Throwable $e) {
            self::report($e);
        }
    }

    private static function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
