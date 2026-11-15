<?php

declare(strict_types=1);

namespace App\Crm;

final class CrmMessages
{
    public static function bn(string $code): string
    {
        return [
            'bad_stage' => 'The step is not valid.',
            'bad_note' => 'The note cannot be empty, and cannot exceed 2000 characters.',
            'bad_title' => 'Give the task name (max 200 characters).',
            'bad_date' => 'The date is not valid.',
            'past_date' => 'A past date cannot be given.',
            'lead_closed' => 'A follow-up cannot be kept on a won or lost lead.',
            'not_found' => 'Not found.',
        ][$code] ?? 'The action could not be done.';
    }

    public static function zone(string $tenantId): string
    {
        $tz = (string) \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenantId)->value('timezone');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Dhaka';
    }
}
