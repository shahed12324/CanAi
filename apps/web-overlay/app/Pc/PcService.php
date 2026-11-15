<?php

declare(strict_types=1);

namespace App\Pc;

/** Pairing codes/tokens and friendly messages around the pc_* functions. Secrets are random, shown once, stored only as sha256. */
final class PcService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I

    public const REASONS = [
        'emergency_stop' => 'The emergency stop is on — no job will run.',
        'companion_mode' => 'Companion mode is on (chat only). Switch to Agent mode to use the computer.',
        'no_policy' => 'This device has no rules.',
        'denied_by_policy' => 'The rules forbid this type of job.',
        'outside_scope' => 'Outside the allowed folders/apps/commands/sites.',
        'bad_params' => 'The request details are wrong or unsafe.',
        'not_found' => 'The device was not found, or it is not yours.',
        'bad_input' => 'The request is not valid.',
    ];

    /** kind => [Bengali label, risk]. Mirrors pc_kind_risk() in the database (which is the authority). */
    public const KINDS = [
        'read_file' => ['Read file', 'low'], 'list_dir' => ['Folder listing', 'low'],
        'write_file' => ['Write file', 'medium'], 'open_app' => ['Open app', 'medium'], 'browser_navigate' => ['Open a site in the browser', 'medium'], 'system_setting' => ['System setting (volume/brightness/Wi-Fi/Bluetooth)', 'medium'],
        'delete_file' => ['Delete file', 'high'], 'run_command' => ['Run command', 'high'], 'input_control' => ['Keyboard/mouse control', 'high'],
        'power' => ['Shutdown/restart/sleep', 'critical'],
        'copy_file' => ['Copy file', 'medium'], 'make_dir' => ['Create folder', 'medium'], 'create_xlsx' => ['Create Excel file', 'medium'], 'create_docx' => ['Create Word file', 'medium'],
        'move_file' => ['Move file', 'high'], 'trash_file' => ['Move file to the recoverable trash', 'high'],
        'screenshot' => ['Take a screenshot (saved as a .png in an allowed folder)', 'high'], 'browser_extract' => ['Read a web page as text', 'medium'],
        'window_info' => ['See which app and window is in front (read-only)', 'medium'], 'wait_window' => ['Wait until a window is in front (read-only)', 'low'],
        'screen_read' => ['Read the text of the front window (screen)', 'high'], 'screen_find' => ['Find a button or text on the front window', 'high'],
        'ui_click' => ['Click a button or text found on the front window', 'high'],
        'screen_describe' => ['Describe the front window', 'high'], 'browser_run' => ['Run a short, approved script in a clean browser (fill forms, click, read)', 'high'],
        'make_report' => ['Make an Excel report from a CSV file (numbers computed on the computer)', 'medium'],
    ];

    /** Why the database refused a plan ("outside_scope:s2" -> a sentence). */
    public static function planReason(string $reason): string
    {
        [$code, $step] = array_pad(explode(':', $reason, 2), 2, '');
        $base = self::REASONS[$code] ?? match ($code) {
            'unknown_kind' => 'A step uses a kind of job that does not exist.',
            default => 'The plan is not valid.',
        };

        return $base.($step !== '' && preg_match('/\A[a-z][a-z0-9_]{0,23}\z/', $step) === 1 ? " (step {$step})" : '');
    }

    public static function plannerMessage(string $status, ?string $reason): string
    {
        return match ($status) {
            'bad_goal' => 'Write what you want in 3–2000 characters.',
            'no_policy' => 'Set at least one allowed folder for this computer first.',
            'no_model' => 'No AI model is available for your plan.',
            'no_credits' => 'Not enough credits.',
            'provider' => 'The AI provider did not answer. Nothing was charged. Try again.',
            'refused' => 'The assistant could not make a safe plan for that. Try describing it with files inside your allowed folders.',
            'rejected' => 'The draft was not accepted by your computer rules: '.self::planReason((string) $reason),
            'invalid' => 'The assistant could not produce a valid plan this time. Try again with a simpler request.',
            default => 'Something went wrong. Try again.',
        };
    }

    public static function planDecisionMessage(string $r): string
    {
        return match ($r) {
            'mobile_required' => 'This step is high risk: approve it in the mobile app with your PIN.',
            'step_up_required' => 'Confirm your PIN in the mobile app first.',
            'not_needed' => 'That step does not need a separate approval.',
            'not_open', 'not_pending' => 'That plan is no longer waiting.',
            'expired' => 'The plan expired. Submit it again.',
            'emergency_stop' => 'Emergency stop is on: nothing can be approved.',
            default => self::REASONS['not_found'],
        };
    }

    /** What the phone may ask its own computer to do (the "hand off to my PC" screen): look-and-open things only. */
    public const MOBILE_KINDS = ['read_file', 'list_dir', 'open_app', 'browser_navigate'];

    public function __construct(private readonly PcRepo $repo, private readonly ?\App\Mobile\ApprovalAlert $alert = null) {}

    /**
     * The phone asks the person's own computer to do one thing.  Same rules as the web form: the device policy decides, the person approves (here on the phone, with the PIN step-up when it is risky).
     *
     * @return array{ok: bool, id: ?string, status: string, message: string, code: string}
     */
    public function mobileRequest(string $tenantId, string $userId, string $deviceId, string $kind, string $target): array
    {
        $t = trim($target);
        if (! in_array($kind, self::MOBILE_KINDS, true) || preg_match('/\A[0-9a-z]{26}\z/', $deviceId) !== 1 || $t === '' || mb_strlen($t) > 520 || preg_match('/[\x00-\x1F\x7F]/', $t) === 1) {
            return ['ok' => false, 'id' => null, 'status' => 'rejected_input', 'message' => 'The details given are not valid.', 'code' => 'bad_input'];
        }
        $r = $this->request($tenantId, $userId, $deviceId, $kind, self::paramsFor($kind, $t), false);   // the person is on the phone already: no alert

        return $r + ['id' => null, 'code' => $r['ok'] ? 'ok' : 'refused'];
    }

    /**
     * The agent's audit upload: {start: int >= 0, rows: 1..200 objects with exactly t,task,step,event,detail,prev,h}.  Shape only - the database recomputes the hashes and checks the links.
     *
     * @param  array<string, mixed>  $in
     * @return ?array{start: int, rows: list<array<string, mixed>>}
     */
    public static function auditBatchFrom(array $in): ?array
    {
        $start = $in['start'] ?? null;
        $rows = $in['rows'] ?? null;
        if (! is_int($start) || $start < 0 || ! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > 200) {
            return null;
        }
        $keys = ['detail', 'event', 'h', 'prev', 'step', 't', 'task'];
        foreach ($rows as $r) {
            if (! is_array($r) || count($r) !== 7) {
                return null;
            }
            $k = array_keys($r);
            sort($k);
            if ($k !== $keys || ! is_int($r['t']) || $r['t'] < 0 || ! is_string($r['task']) || ! is_string($r['step']) || ! is_string($r['event']) || ! is_string($r['detail']) || ! is_string($r['prev']) || ! is_string($r['h'])) {
                return null;
            }
        }

        return ['start' => $start, 'rows' => $rows];
    }

    /**
     * Form input -> what pc_policy_save wants. Lists come one item per line; blanks and repeats are dropped (the database validates the rest).
     * Only deny/allow are kept in modes ('ask' is the default); unknown kinds and 'allow' on high/critical kinds are ignored here and refused by the database anyway.
     *
     * @param  array<string, mixed>  $in
     * @return array{roots: list<string>, apps: list<string>, commands: list<string>, hosts: list<string>, modes: array<string, string>}
     */
    public static function parsePolicy(array $in): array
    {
        $lines = function (mixed $v, bool $lower = false): array {
            $out = [];
            foreach (preg_split('/\R/u', is_string($v) ? $v : '') ?: [] as $l) {
                $l = trim($l);
                $l = $lower ? mb_strtolower($l) : $l;
                if ($l !== '' && ! in_array($l, $out, true)) {
                    $out[] = $l;
                }
            }

            return array_slice($out, 0, 100);
        };
        $modes = [];
        foreach ((is_array($in['modes'] ?? null) ? $in['modes'] : []) as $kind => $mode) {
            if (isset(self::KINDS[$kind]) && in_array($mode, ['deny', 'allow'], true)) {
                $modes[$kind] = $mode;
            }
        }

        return ['roots' => $lines($in['roots'] ?? ''), 'apps' => $lines($in['apps'] ?? ''), 'commands' => $lines($in['commands'] ?? ''), 'hosts' => $lines($in['hosts'] ?? '', true), 'modes' => $modes];
    }

    /**
     * One-line form input -> params for an action kind. Commands are split on spaces into program + literal arguments (no shell is ever involved).
     *
     * @return array<string, mixed>
     */
    public static function paramsFor(string $kind, string $target): array
    {
        $t = trim($target);

        return match ($kind) {
            'read_file', 'list_dir', 'write_file', 'delete_file', 'make_dir', 'trash_file', 'create_xlsx', 'create_docx', 'screenshot' => ['path' => $t],
            // "from -> to" on one line; both ends are checked against the allowed folders by the database
            'copy_file', 'move_file' => (function () use ($t): array {
                [$a, $b] = array_pad(array_map('trim', explode('->', $t, 2)), 2, '');

                return ['src' => $a, 'dst' => $b];
            })(),
            'open_app' => ['app' => $t],
            'window_info', 'screen_read' => [],
            // "Save" or "Save | button | 2" (text | role | index) — only text is required
            'screen_find', 'ui_click' => (function () use ($t): array {
                [$a, $b, $c] = array_pad(array_map('trim', explode('|', $t, 3)), 3, '');
                $p = array_filter(['text' => $a, 'role' => $b], fn ($v) => $v !== '');

                return $c !== '' && ctype_digit($c) ? $p + ['index' => (int) $c] : $p;
            })(),
            // the whole script as JSON: {"url":"https://...","steps":[{"op":"fill","label":"Name","value":"..."}, ...]}
            'browser_run' => (function () use ($t): array {
                $j = json_decode($t, true);

                return is_array($j) ? $j : ['steps' => []];
            })(),
            // "data.csv -> report.xlsx" or "data.csv -> report.xlsx | region" (group by a column)
            'make_report' => (function () use ($t): array {
                [$paths, $g] = array_pad(array_map('trim', explode('|', $t, 2)), 2, '');
                [$a, $b] = array_pad(array_map('trim', explode('->', $paths, 2)), 2, '');
                $p = ['src' => $a, 'dst' => $b];

                return $g !== '' ? $p + ['group_by' => $g] : $p;
            })(),
            'screen_describe' => $t === '' ? [] : ['question' => $t],
            // "notepad" or "notepad | Untitled" (app | title text), optional " | 15" seconds
            'wait_window' => (function () use ($t): array {
                [$a, $b, $c] = array_pad(array_map('trim', explode('|', $t, 3)), 3, '');
                $p = array_filter(['process' => $a, 'title_contains' => $b], fn ($v) => $v !== '');

                return $c !== '' && ctype_digit($c) ? $p + ['timeout' => (int) $c] : $p;
            })(),
            'browser_navigate', 'browser_extract' => ['url' => $t],
            'run_command' => (function () use ($t): array {
                $parts = preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $p = ['command' => (string) array_shift($parts)];

                return $parts === [] ? $p : $p + ['args' => $parts];
            })(),
            'system_setting' => (function () use ($t): array {
                [$k, $v] = array_pad(explode('=', $t, 2), 2, '');
                $v = trim($v);
                $val = match (true) {
                    in_array(strtolower($v), ['on', 'true'], true) => true,
                    in_array(strtolower($v), ['off', 'false'], true) => false,
                    is_numeric($v) => $v + 0,
                    default => $v,
                };

                return ['setting' => trim($k), 'value' => $val];
            })(),
            default => ['action' => $t],          // input_control, power
        };
    }

    /** @return array{ok: bool, message: string} */
    public function savePolicy(string $tenantId, string $userId, string $deviceId, array $in): array
    {
        $r = $this->repo->policySave($tenantId, $userId, $deviceId, self::parsePolicy($in));

        return match ($r) {
            'ok' => ['ok' => true, 'message' => 'Rule saved.'],
            'not_found' => ['ok' => false, 'message' => self::REASONS['not_found']],
            default => ['ok' => false, 'message' => 'There is an error in the rules: a folder must be a full path (not a whole drive or `..` ), a command must be just a program name, a site a lowercase domain, and a risky job "Automatic" cannot be done.'],
        };
    }

    /**
     * The agent's metrics body -> repo shape, or null when required numbers are missing/not numeric.
     *
     * @param  array<string, mixed>  $in
     * @return ?array<string, mixed>
     */
    public static function metricsFrom(array $in): ?array
    {
        $num = fn (mixed $v): ?float => (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float) $v : null;
        $m = ['cpu' => $num($in['cpu'] ?? null), 'mem' => $num($in['mem'] ?? null), 'disk' => $num($in['disk'] ?? null)];
        if (in_array(null, $m, true)) {
            return null;
        }
        foreach (['gpu', 'battery', 'temp_c'] as $k) {
            $m[$k] = $num($in[$k] ?? null);
        }
        $m['charging'] = is_bool($in['charging'] ?? null) ? $in['charging'] : null;

        return $m;
    }


    /** @return array{ok: bool, code?: string, error?: string} the code is shown ONCE (QR/text); only its hash is stored */
    public function startPairing(string $tenantId, string $userId, string $name, string $platform): array
    {
        $code = self::randomCode(10);
        $r = $this->repo->pairCreate($tenantId, $userId, $name, $platform, self::hash($code));

        return match ($r) {
            'ok' => ['ok' => true, 'code' => $code],
            'limit' => ['ok' => false, 'error' => 'At most 5 active devices/pending pairings — remove one first.'],
            'forbidden' => ['ok' => false, 'error' => 'You are not a member of this workspace.'],
            default => ['ok' => false, 'error' => 'Fix the name (1–60 characters) and platform.'],
        };
    }

    /** The desktop agent redeems the code; returns its device token ONCE. @return ?array{device_id: string, token: string} */
    public function redeem(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        if (preg_match('/\A['.self::ALPHABET.']{10}\z/', $code) !== 1) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $r = $this->repo->pairRedeem(self::hash($code), self::hash($token));

        return $r === null ? null : ['device_id' => trim($r['device_id']), 'token' => $token];
    }

    /** The agent swaps its own token for one it made itself.  Both must be 64 hex characters.  The old one stops working at once. */
    public function rotate(string $oldBearer, string $newToken): bool
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $oldBearer) !== 1 || preg_match('/\A[0-9a-f]{64}\z/', $newToken) !== 1 || $oldBearer === $newToken) {
            return false;
        }

        return $this->repo->agentRotate(self::hash($oldBearer), self::hash($newToken)) !== null;
    }

    /** @return ?array{device_id: string, tenant_id: string, user_id: string} */
    public function authenticate(string $bearer): ?array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $bearer) !== 1) {
            return null;
        }
        $r = $this->repo->agentLookup(self::hash($bearer));

        return $r === null ? null : array_map(fn ($v) => trim((string) $v), $r);
    }

    /** @return array{ok: bool, id?: string, status: string, message: string} */
    public function request(string $tenantId, string $userId, string $deviceId, string $kind, array $params, bool $alert = true): array
    {
        $r = $this->repo->actionRequest($tenantId, $userId, $deviceId, $kind, $params);
        $status = (string) ($r['status'] ?? 'rejected_input');
        if ($alert && $status === 'pending') {
            $this->alert?->computerWaiting($tenantId, $userId, false);     // best effort, never throws
        }
        $reason = (string) ($r['reason'] ?? '');
        $msg = match ($status) {
            'approved' => 'Approved — the device will do it shortly.',
            'pending' => 'Awaiting your approval.',
            default => self::REASONS[$reason] ?? 'The job cannot be done.',
        };

        return ['ok' => in_array($status, ['approved', 'pending'], true), 'id' => isset($r['id']) ? trim((string) $r['id']) : null, 'status' => $status, 'message' => $msg];
    }

    /** What the phone API answers for each outcome of PcRepo::mobileDecide. @return array{status: int, code: string, message: string} */
    public static function mobileOutcome(string $r): array
    {
        return match ($r) {
            'ok' => ['status' => 200, 'code' => 'ok', 'message' => self::decisionMessage('ok')],
            'step_up_required' => ['status' => 403, 'code' => 'step_up_required', 'message' => self::decisionMessage('step_up_required')],
            'not_pending' => ['status' => 409, 'code' => 'not_pending', 'message' => self::decisionMessage('not_pending')],
            'expired' => ['status' => 410, 'code' => 'expired', 'message' => self::decisionMessage('expired')],
            'emergency_stop' => ['status' => 423, 'code' => 'emergency_stop', 'message' => self::decisionMessage('emergency_stop')],
            'bad_input' => ['status' => 422, 'code' => 'bad_input', 'message' => 'The details given are not valid.'],
            default => ['status' => 404, 'code' => 'not_found', 'message' => self::decisionMessage('not_found')],
        };
    }

    public static function decisionMessage(string $r): string
    {
        return match ($r) {
            'ok' => 'Done.',
            'not_pending' => 'The job is no longer pending.',
            'expired' => 'Expired — request again.',
            'emergency_stop' => 'The emergency stop is on — the job was stopped.',
            'mobile_required' => 'This is a high-risk job — it can be approved only with a PIN in the mobile app. Here you can only cancel it.',
            'step_up_required' => 'First confirm with your PIN to approve.',
            default => 'The job was not found, or it is not from your device.',
        };
    }

    public static function hash(string $s): string
    {
        return hash('sha256', $s);
    }

    private static function randomCode(int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}
