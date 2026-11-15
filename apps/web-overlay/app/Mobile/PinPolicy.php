<?php

declare(strict_types=1);

namespace App\Mobile;

/** A 6-digit PIN that is not trivially guessable. The server never sees it again after hashing. */
final class PinPolicy
{
    private const WEAK = ['123456', '654321', '121212', '112233', '123123', '000000', '696969', '123321', '159753', '246810', '135790', '102030'];

    /** @return string|null  null = fine, otherwise a Bengali reason */
    public static function problem(string $pin): ?string
    {
        if (preg_match('/\A[0-9]{6}\z/', $pin) !== 1) {
            return 'The PIN must be 6 English digits.';
        }
        if (count(array_unique(str_split($pin))) === 1 || in_array($pin, self::WEAK, true) || self::sequence($pin)) {
            return 'This PIN is easy to guess. Pick another PIN.';
        }

        return null;
    }

    private static function sequence(string $pin): bool
    {
        $d = array_map('intval', str_split($pin));
        $up = $down = true;
        for ($i = 1; $i < 6; $i++) {
            $up = $up && $d[$i] === ($d[$i - 1] + 1) % 10;
            $down = $down && $d[$i] === ($d[$i - 1] + 9) % 10;
        }

        return $up || $down;
    }
}
