<?php

declare(strict_types=1);

namespace App\Studio;

use App\Actions\Tenancy\CreateWorkspace;
use App\Ai\ProviderCallException;
use App\Credits\CreditException;
use App\Credits\CreditLedger;

/**
 * Image Studio.  Money invariant: the only paid step is the AI picture; its reservation ends in exactly one settle (the provider answered) or one release (nothing usable came back).
 * Everything else - drawing text, logo and sizes, saving, client links - is free and never touches credits.
 * The words are drawn by the engine with real fonts, never by the picture model, so Bengali is always spelled right.
 */
final class StudioService
{
    public const SIZES = ['post', 'portrait', 'story', 'cover', 'ad'];

    public const THEMES = ['dark', 'light', 'brand'];

    public const GEN_SIZE = ['post' => '1024x1024', 'portrait' => '1024x1536', 'story' => '1024x1536', 'cover' => '1536x1024', 'ad' => '1536x1024'];

    public const MAX_PHOTO_BYTES = 4_000_000;

    public const MAX_PHOTO_PIXELS = 25_000_000;

    public function __construct(
        private readonly StudioEngine $engine,
        private readonly ImagePicker $picker,
        private readonly CreditLedger $credits,
        private readonly ImagePricing $pricing,
        private readonly DesignRepo $designs,
    ) {}

    /** Printable single line: no control or bidi-override characters, single spaces. */
    public static function line(?string $v, int $max): string
    {
        $v = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f\x{2028}\x{2029}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', ' ', (string) $v);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, $max);
    }

    /** @return array{status: string, base64: ?string} a picture the person uploaded as the background: real PNG/JPEG/WEBP, small enough */
    public static function photo(string $bytes): array
    {
        $bad = ['status' => 'bad_photo', 'base64' => null];
        if ($bytes === '' || strlen($bytes) > self::MAX_PHOTO_BYTES) {
            return $bad;
        }
        $magic = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || str_starts_with($bytes, "\xFF\xD8\xFF") || (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP');
        $info = $magic ? @getimagesizefromstring($bytes) : false;
        if ($info === false || $info[0] * $info[1] > self::MAX_PHOTO_PIXELS || $info[0] < 1 || $info[1] < 1) {
            return $bad;
        }

        return ['status' => 'ok', 'base64' => base64_encode($bytes)];
    }

    /**
     * Asks the picture model for a background.  Costs credits.
     *
     * @return array{status: string, image_b64: ?string, credits: string}
     */
    public function background(string $tenantId, string $userId, string $prompt, string $size, string $requestId): array
    {
        $fail = fn (string $s, string $c = '0.0000') => ['status' => $s, 'image_b64' => null, 'credits' => $c];
        $prompt = self::line($prompt, 1500);
        if (mb_strlen($prompt) < 3) {
            return $fail('bad_prompt');
        }
        $genSize = self::GEN_SIZE[$size] ?? null;
        if ($genSize === null) {
            return $fail('bad_size');
        }
        $model = $this->picker->pick($tenantId);
        if ($model === null) {
            return $fail('no_model');
        }
        $amount = $this->pricing->image(1, $model['credit_multiplier'] ?? null);
        try {
            $reservation = $this->credits->reserve($tenantId, $amount, 'image:'.$requestId, $requestId)['reservation_id'];
        } catch (CreditException $e) {
            return $fail($e->errorCode === 'insufficient_credits' ? 'no_credits' : 'failed');
        } catch (\Throwable $e) {
            self::report($e);

            return $fail('failed');
        }
        $result = null;
        $status = 'failed';
        try {
            $result = $this->engine->image($model['provider_id'], $model['model_key'], $prompt, $genSize, $tenantId, $userId);
        } catch (ProviderCallException) {
            $status = 'provider';
        } catch (\Throwable $e) {
            self::report($e);
        }
        $b64 = is_array($result) && ($result['status'] ?? '') === 'ok' && is_string($result['image_b64'] ?? null) && $result['image_b64'] !== '' ? $result['image_b64'] : null;
        if ($b64 === null) {
            $this->releaseQuietly($tenantId, $reservation, $requestId);

            return $fail(is_array($result) && ($result['status'] ?? '') === 'unsupported' ? 'unsupported' : $status);
        }
        $charged = $amount;
        try {
            $charged = $this->credits->settle($tenantId, $reservation, $amount, 'image-settle:'.$requestId, [
                'source' => 'image', 'user_id' => $userId, 'request_id' => $requestId, 'provider_id' => $model['provider_id'], 'model_id' => $model['id'], 'input_tokens' => 0, 'output_tokens' => 0,
            ])['charged'] ?? $amount;
        } catch (\Throwable $e) {
            self::report($e);     // the hold is returned by credit_release_stale()
        }

        return ['status' => 'ok', 'image_b64' => $b64, 'credits' => (string) $charged];
    }

    /**
     * A logo idea: one square picture from the picture model, for the person to look at and download (it is not saved anywhere and not set as the brand logo).
     * Costs the same as any picture.  The prompt is wrapped so the model draws a flat mark without letters (letters are never drawn by the model; Bengali would be misspelled).
     *
     * @return array{status: string, image_b64: ?string, credits: string}
     */
    public function logo(string $tenantId, string $userId, string $idea, string $requestId): array
    {
        $idea = self::line($idea, 400);
        if (mb_strlen($idea) < 3) {
            return ['status' => 'bad_prompt', 'image_b64' => null, 'credits' => '0.0000'];
        }

        return $this->background($tenantId, $userId, 'A simple flat vector-style logo mark on a plain white background, centered, no text, no letters, no words. Idea: '.$idea, 'post', $requestId);
    }

    /**
     * Makes and saves a design.  source: none (brand gradient) | upload (the person's own photo, bytes in $photo) | ai (the picture model draws it from $in['prompt']).
     *
     * @param  array<string, mixed>  $in  title, headline, subline, cta, theme, sizes[], brief, prompt
     * @param  array{primary: string, accent: string, name: string, footer: string, logo_b64: ?string}  $brand
     * @return array{status: string, id: ?string, credits: string}
     */
    public function create(string $tenantId, string $userId, array $in, array $brand, string $source, ?string $photo, string $requestId): array
    {
        $fail = fn (string $s, string $c = '0.0000') => ['status' => $s, 'id' => null, 'credits' => $c];
        $title = self::line((string) ($in['title'] ?? ''), 120);
        $head = self::line((string) ($in['headline'] ?? ''), 200);
        $sub = self::line((string) ($in['subline'] ?? ''), 400);
        $cta = self::line((string) ($in['cta'] ?? ''), 100);
        $theme = (string) ($in['theme'] ?? 'dark');
        $brief = self::line((string) ($in['brief'] ?? ''), 1500);
        $sizes = array_values(array_unique(array_filter((array) ($in['sizes'] ?? []), fn ($s) => is_string($s) && in_array($s, self::SIZES, true))));
        if ($title === '') {
            return $fail('bad_title');
        }
        if (mb_strlen((string) ($in['headline'] ?? '')) > 200 || mb_strlen((string) ($in['subline'] ?? '')) > 400 || mb_strlen((string) ($in['cta'] ?? '')) > 100) {
            return $fail('bad_text');
        }
        if ($sizes === []) {
            return $fail('bad_sizes');
        }
        if (! in_array($theme, self::THEMES, true)) {
            return $fail('bad_theme');
        }
        if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $brand['primary']) !== 1 || preg_match('/\A#[0-9a-fA-F]{6}\z/', $brand['accent']) !== 1) {
            return $fail('bad_brand');
        }
        if ($this->designs->count($userId) >= 40) {
            return $fail('limit');
        }
        $base = null;
        $credits = '0.0000';
        if ($source === 'upload') {
            $p = self::photo((string) $photo);
            if ($p['status'] !== 'ok') {
                return $fail('bad_photo');
            }
            $base = $p['base64'];
        } elseif ($source === 'ai') {
            $bg = $this->background($tenantId, $userId, (string) ($in['prompt'] ?? ''), $sizes[0], $requestId);
            $credits = $bg['credits'];
            if ($bg['status'] !== 'ok') {
                return $fail($bg['status'], $credits);
            }
            $base = $bg['image_b64'];
        } elseif ($source !== 'none') {
            return $fail('bad_input');
        }
        $id = CreateWorkspace::newId();
        $meta = ['primary' => strtolower($brand['primary']), 'accent' => strtolower($brand['accent']), 'name' => self::line($brand['name'], 100), 'footer' => self::line($brand['footer'], 240)];
        $r = $this->designs->save($tenantId, $userId, $id, $title, $brief, $head, $sub, $cta, $theme, $sizes, $meta, $brand['logo_b64'] ?? null, $base);

        return $r === 'ok' ? ['status' => 'ok', 'id' => $id, 'credits' => $credits] : $fail($r === 'limit' ? 'limit' : 'failed', $credits);
    }

    /**
     * Edits the words, name, theme and sizes of my own design (pictures are never touched, nothing is charged).
     * A change keeps the previous state as a version; saving the same thing again is reported as 'unchanged'.
     *
     * @param  array<string, mixed>  $in
     * @return string ok|unchanged|bad_title|bad_text|bad_sizes|bad_theme|not_found|failed
     */
    public function edit(string $tenantId, string $userId, string $id, array $in): string
    {
        $title = self::line((string) ($in['title'] ?? ''), 120);
        $head = self::line((string) ($in['headline'] ?? ''), 200);
        $sub = self::line((string) ($in['subline'] ?? ''), 400);
        $cta = self::line((string) ($in['cta'] ?? ''), 100);
        $theme = (string) ($in['theme'] ?? '');
        $sizes = array_values(array_unique(array_filter((array) ($in['sizes'] ?? []), fn ($s) => is_string($s) && in_array($s, self::SIZES, true))));
        if ($title === '') {
            return 'bad_title';
        }
        if (mb_strlen((string) ($in['headline'] ?? '')) > 200 || mb_strlen((string) ($in['subline'] ?? '')) > 400 || mb_strlen((string) ($in['cta'] ?? '')) > 100) {
            return 'bad_text';
        }
        if ($sizes === []) {
            return 'bad_sizes';
        }
        if (! in_array($theme, self::THEMES, true)) {
            return 'bad_theme';
        }
        $r = $this->designs->update($tenantId, $userId, $id, $title, $head, $sub, $cta, $theme, $sizes);

        return in_array($r, ['ok', 'unchanged', 'not_found'], true) ? $r : 'failed';
    }

    /** Brings back an earlier version of my own design (the current state is kept as a version first). @return string ok|not_found */
    public function restore(string $tenantId, string $userId, string $id, int $n): string
    {
        return $this->designs->restore($tenantId, $userId, $id, $n) === 'ok' ? 'ok' : 'not_found';
    }

    /**
     * Draws the requested sizes of a saved design.
     *
     * @param  array<string, mixed>  $design  a row from DesignRepo::find()
     * @param  list<string>  $sizes
     * @return array{status: string, items: array<string, array{mime: string, bytes: string, width: int, height: int}>}
     */
    public function render(string $tenantId, string $userId, array $design, array $sizes, string $fmt = 'png'): array
    {
        $sizes = array_values(array_unique(array_filter($sizes, fn ($s) => in_array($s, $design['sizes'], true))));
        if ($sizes === []) {
            return ['status' => 'bad_size', 'items' => []];
        }
        $brand = $design['brand'];
        $payload = [
            'copy' => ['headline' => $design['headline'], 'subline' => $design['subline'], 'cta' => $design['cta'], 'theme' => $design['theme']],
            'brand' => ['primary' => (string) ($brand['primary'] ?? '#1f3a8a'), 'accent' => (string) ($brand['accent'] ?? '#f59e0b'), 'name' => (string) ($brand['name'] ?? ''),
                'footer' => (string) ($brand['footer'] ?? ''), 'logo_b64' => $design['logo_b64']],
            'base_b64' => $design['base_b64'],
            'sizes' => $sizes,
            'fmt' => $fmt === 'jpeg' ? 'jpeg' : 'png',
        ];
        try {
            $r = $this->engine->compose($payload, $tenantId, $userId);
        } catch (\Throwable $e) {
            if (! $e instanceof ProviderCallException) {
                self::report($e);
            }

            return ['status' => 'engine', 'items' => []];
        }
        if (($r['status'] ?? '') !== 'ok' || ! is_array($r['items'] ?? null)) {
            return ['status' => match ($r['reason'] ?? '') {
                'bad_logo', 'bad_color' => 'bad_brand',
                'no_raqm' => 'no_shaping',
                default => 'engine',
            }, 'items' => []];
        }
        $out = [];
        foreach ($r['items'] as $it) {
            $bytes = is_array($it) && is_string($it['image_b64'] ?? null) ? base64_decode($it['image_b64'], true) : false;
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $out[(string) $it['size']] = ['mime' => (string) $it['mime'], 'bytes' => $bytes, 'width' => (int) $it['width'], 'height' => (int) $it['height']];
        }

        return ['status' => $out === [] ? 'engine' : 'ok', 'items' => $out];
    }

    /**
     * A client-approval link: the exact picture is frozen into the link, only the hash of the random token is stored.
     *
     * @return array{status: string, token: ?string}
     */
    public function share(string $tenantId, string $userId, string $designId, string $size, string $client, int $days): array
    {
        $design = $this->designs->find($userId, $designId);
        if ($design === null) {
            return ['status' => 'not_found', 'token' => null];
        }
        if (! in_array($size, $design['sizes'], true)) {
            return ['status' => 'bad_size', 'token' => null];
        }
        $r = $this->render($tenantId, $userId, $design, [$size], 'jpeg');
        if ($r['status'] !== 'ok' || ! isset($r['items'][$size])) {
            return ['status' => $r['status'], 'token' => null];
        }
        $token = bin2hex(random_bytes(32));
        $st = $this->designs->createReview($tenantId, $userId, CreateWorkspace::newId(), $designId, hash('sha256', $token), self::line($client, 80), $size, base64_encode($r['items'][$size]['bytes']), max(1, min(30, $days)));

        return match ($st) {
            'ok' => ['status' => 'ok', 'token' => $token],
            'limit' => ['status' => 'review_limit', 'token' => null],
            'not_found' => ['status' => 'not_found', 'token' => null],
            default => ['status' => 'failed', 'token' => null],
        };
    }

    /** @return ?array{title: string, brand_name: string, client_name: string, size: string, preview_b64: string, status: string, comment: ?string, decided_at: ?string, expires_at: string} what a client may see; null for an unknown link */
    public function publicView(string $token): ?array
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1 ? $this->designs->publicView(hash('sha256', $token)) : null;
    }

    /** @return string ok|not_found|expired|decided|revoked|bad_input */
    public function decide(string $token, string $decision, string $comment): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return 'not_found';
        }

        return $this->designs->publicDecide(hash('sha256', $token), $decision, self::line($comment, 1000));
    }

    private function releaseQuietly(string $tenantId, string $reservation, string $key): void
    {
        try {
            $this->credits->release($tenantId, $reservation, 'image-release:'.$key);
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
