<?php

declare(strict_types=1);

namespace App\Skills;

use Illuminate\Support\Facades\DB;

/** "/daily-sales last week" → the full prompt for that skill. Unknown or archived names leave the message untouched, so a normal "/" message still works. Needs an active tenant context. */
final class SkillExpander
{
    public static function expand(string $tenantId, string $userId, string $text): string
    {
        $cmd = SkillText::command($text);
        if ($cmd === null) {
            return $text;
        }
        $s = DB::table('skills')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->where('slug', $cmd[0])->first(['id', 'name', 'instructions', 'description']);
        if ($s === null) {
            return $text;
        }
        DB::table('skills')->where('id', $s->id)->where('tenant_id', $tenantId)->update(['use_count' => DB::raw('use_count + 1'), 'last_used_at' => now()]);

        return SkillText::compose(['name' => $s->name, 'instructions' => $s->instructions], $cmd[1]);
    }
}
