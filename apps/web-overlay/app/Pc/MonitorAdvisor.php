<?php

declare(strict_types=1);

namespace App\Pc;

/** System Monitor: turns one sample into human alerts (Bengali). Pure. Missing sensors (null) never raise an alert. */
final class MonitorAdvisor
{
    public const CPU = 90.0;

    public const MEM = 90.0;

    public const DISK = 90.0;

    public const TEMP = 85.0;

    public const BATTERY = 15.0;

    /**
     * @param  array{cpu?: ?float, mem?: ?float, disk?: ?float, gpu?: ?float, battery?: ?float, temp_c?: ?float, charging?: ?bool}  $s
     * @return list<array{code: string, level: string, message: string}>
     */
    public static function alerts(array $s): array
    {
        $out = [];
        $add = function (string $code, string $level, string $msg) use (&$out): void {
            $out[] = ['code' => $code, 'level' => $level, 'message' => $msg];
        };
        if (($s['cpu'] ?? null) !== null && $s['cpu'] >= self::CPU) {
            $add('cpu_high', 'warn', 'CPU Uses '.self::n($s['cpu']).'% — Heavy work in progress.');
        }
        if (($s['mem'] ?? null) !== null && $s['mem'] >= self::MEM) {
            $add('mem_high', 'warn', 'RAM '.self::n($s['mem']).'% Full — close some apps.');
        }
        if (($s['disk'] ?? null) !== null && $s['disk'] >= self::DISK) {
            $add('disk_full', 'warn', 'Disk '.self::n($s['disk']).'% Full — free up space.');
        }
        if (($s['gpu'] ?? null) !== null && $s['gpu'] >= self::CPU) {
            $add('gpu_high', 'info', 'GPU Uses '.self::n($s['gpu']).'%.');
        }
        if (($s['temp_c'] ?? null) !== null && $s['temp_c'] >= self::TEMP) {
            $add('temp_high', 'critical', 'Temperature '.self::n($s['temp_c']).'°C — Overheating.');
        }
        if (($s['battery'] ?? null) !== null && $s['battery'] <= self::BATTERY && ($s['charging'] ?? null) !== true) {
            $add('battery_low', 'warn', 'Battery '.self::n($s['battery']).'% — Charge it.');
        }

        return $out;
    }

    private static function n(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }
}
