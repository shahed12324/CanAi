<?php

declare(strict_types=1);

namespace App\Mobile;

/** Phone-side rules: PIN set/check, step-up, approval decisions and emergency stop. The database enforces the same rules again. */
final class MobileService
{
    public function __construct(private readonly MobileRepo $repo, private readonly ?\Closure $now = null) {}

    public function register(string $tenant, string $user, int $tokenId, string $name, string $platform, ?string $pushToken, ?string $appVersion): array
    {
        if (! in_array($platform, ['android', 'ios'], true)) {
            throw new \InvalidArgumentException('platform');
        }
        if ($pushToken !== null && ! self::validPushToken($pushToken)) {
            $pushToken = null;
        }
        $id = $this->repo->registerDevice($tenant, $user, $tokenId, $name, $platform, $pushToken, $appVersion);
        $device = $this->repo->device($tenant, $user, $tokenId);

        return ['device_id' => $id, 'has_pin' => (bool) ($device['has_pin'] ?? false)];
    }

    public static function validPushToken(string $t): bool
    {
        return preg_match('/\AExpo(nent)?PushToken\[[A-Za-z0-9_\-]{10,60}\]\z/', $t) === 1;
    }

    /** @return array{ok: bool, error?: string, message?: string} */
    public function setPin(array $device, string $user, string $pin): array
    {
        if (($problem = PinPolicy::problem($pin)) !== null) {
            return ['ok' => false, 'error' => 'weak_pin', 'message' => $problem];
        }
        $this->repo->setPin($device['id'], $user, password_hash($pin, PASSWORD_BCRYPT, ['cost' => 11]));

        return ['ok' => true];
    }

    /** @return array{result: string, expires_in?: int} result: ok|bad|locked|no_pin */
    public function stepUp(array $device, string $user, string $pin): array
    {
        if (! $device['has_pin'] || $device['pin_hash'] === null) {
            return ['result' => 'no_pin'];
        }
        if ($this->locked($device['locked_until'])) {
            return ['result' => 'locked'];                       // a locked device is not even checked, so a lock cannot be probed
        }
        $ok = preg_match('/\A[0-9]{6}\z/', $pin) === 1 && password_verify($pin, $device['pin_hash']);
        $result = $this->repo->pinAttempt($device['id'], $user, $ok);

        return $result === 'ok' ? ['result' => 'ok', 'expires_in' => 300] : ['result' => $result];
    }

    /** @return string see MobileRepo::decide */
    public function decide(array $device, string $user, string $kind, string $ref, string $decision): string
    {
        return $this->repo->decide($device['id'], $user, $kind, $ref, $decision);
    }

    /** @return string stopped|resumed|unchanged|step_up_required */
    public function emergency(string $tenant, array $device, string $user, bool $stop, string $reason): string
    {
        $r = $this->repo->emergency($device['id'], $user, $stop, trim($reason));
        if ($r === 'stopped' || $r === 'resumed') {
            foreach ($this->repo->deviceUsers($tenant) as $u) {
                $this->repo->notify($tenant, $u, 'emergency', $r === 'stopped' ? 'The emergency stop is on' : 'Jobs are running again',
                    $r === 'stopped' ? 'All automatic jobs in the workspace are paused.' : 'Automatic jobs will run again.', 'emergency', null);
            }
        }

        return $r;
    }

    private function locked(?string $until): bool
    {
        if ($until === null) {
            return false;
        }
        $now = $this->now !== null ? ($this->now)() : time();

        return strtotime($until) > $now;
    }
}
