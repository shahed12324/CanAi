<?php

declare(strict_types=1);

namespace App\Couriers;

use App\Orders\OrderText;

/** Turns the report numbers into the lines the owner reads. Pure. */
final class ReportText
{
    public static function bn(int|string $n): string
    {
        return (string) $n;   // array form: strtr(from, to) is byte-based and would break multibyte digits
    }

    /** @param array<string, mixed> $d @return list<string> */
    public static function lines(array $d, string $currency = 'BDT'): array
    {
        $c = $d['created'] ?? ['count' => 0, 'value_minor' => 0, 'by_agent' => 0, 'risky' => 0];
        $dl = $d['delivered'] ?? ['count' => 0, 'value_minor' => 0];
        $cod = $d['cod_pending'] ?? ['count' => 0, 'value_minor' => 0];
        $out = [];
        $out[] = 'New orders: '.self::bn($c['count']).'('.OrderText::money((int) $c['value_minor'], $currency).')'.($c['by_agent'] > 0 ? ' — of which by the AI agent '.self::bn($c['by_agent']).' items' : '').($c['risky'] > 0 ? ', risky '.self::bn($c['risky']).' items' : '');
        $out[] = 'Confirmed '.self::bn((int) ($d['confirmed'] ?? 0)).' · Sent to courier '.self::bn((int) ($d['shipped'] ?? 0)).' · Cancel '.self::bn((int) ($d['cancelled'] ?? 0));
        $out[] = 'Arrived: '.self::bn($dl['count']).'('.OrderText::money((int) $dl['value_minor'], $currency).') · Returned: '.self::bn((int) ($d['returned'] ?? 0)).' items';
        $open = [];
        foreach (($d['open'] ?? []) as $st => $n) {
            $open[] = OrderText::statusBn((string) $st).' '.self::bn((int) $n);
        }
        $out[] = 'In progress now: '.($open === [] ? 'None' : implode(' · ', $open));
        $out[] = 'Awaiting collection in the courier’s hands (COD): '.self::bn($cod['count']).' parcels, '.OrderText::money((int) $cod['value_minor'], $currency);
        if ((int) ($d['attention'] ?? 0) > 0) {
            $out[] = '⚠ '.self::bn((int) $d['attention']).' parcels need your attention (status unclear or check failed).';
        }
        foreach (($d['couriers'] ?? []) as $k) {
            $t = (int) $k['delivered'] + (int) $k['bad'];
            $out[] = ucfirst((string) $k['courier']).' (30 days): arrived '.self::bn((int) $k['delivered']).', Returned/cancelled '.self::bn((int) $k['bad']).($t > 0 ? ' — Success '.self::bn((int) round(100 * $k['delivered'] / $t)).'%' : '');
        }

        return $out;
    }
}
