<?php

declare(strict_types=1);

namespace App\Proposals;

final class DocDate
{
    private const BN_MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

    public static function format(\DateTimeInterface $d, string $lang): string
    {
        if ($lang === 'bn') {
            return Money::toBnDigits($d->format('d')).' '.self::BN_MONTHS[(int) $d->format('n') - 1].' '.Money::toBnDigits($d->format('Y'));
        }

        return $d->format('d M Y');
    }
}
