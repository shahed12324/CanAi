<?php

declare(strict_types=1);

namespace App\Productivity;

use DateTimeImmutable;

/**
 * "What should I do today?" - worked out from the person's own open tasks, no AI and no cost.
 * Order: overdue (oldest first), due today, reminders set for today, due in the next 3 days, then the important tasks with no date.
 * Inside each group the more important (priority 1) come first.
 */
final class DailyPlan
{
    public const FOCUS = 5;

    /**
     * @param  list<array{id: string, title: string, priority: int, due_on: ?string, remind_at: ?string}>  $open  open tasks only
     * @param  string  $today  Y-m-d in the person's own zone
     * @return array{overdue: list<array<string, mixed>>, today: list<array<string, mixed>>, reminders: list<array<string, mixed>>, soon: list<array<string, mixed>>, anytime: list<array<string, mixed>>, focus: list<array<string, mixed>>}
     */
    public static function build(array $open, string $today, string $tz): array
    {
        $zone = TaskInput::zone($tz);
        $soonTo = (new DateTimeImmutable($today, $zone))->modify('+3 days')->format('Y-m-d');
        $g = ['overdue' => [], 'today' => [], 'reminders' => [], 'soon' => [], 'anytime' => []];
        foreach ($open as $t) {
            $due = $t['due_on'];
            $remDay = $t['remind_at'] === null ? null : (new DateTimeImmutable($t['remind_at']))->setTimezone($zone)->format('Y-m-d');
            if ($due !== null && $due < $today) {
                $g['overdue'][] = $t;
            } elseif ($due === $today) {
                $g['today'][] = $t;
            } elseif ($remDay === $today && ($due === null || $due > $today)) {
                $g['reminders'][] = $t;
            } elseif ($due !== null && $due <= $soonTo) {
                $g['soon'][] = $t;
            } elseif ($due === null && $t['priority'] === 1) {
                $g['anytime'][] = $t;
            }
        }
        $byDue = fn (array $a, array $b) => [$a['due_on'] ?? '9999', $a['priority'], $a['title']] <=> [$b['due_on'] ?? '9999', $b['priority'], $b['title']];
        $byPrio = fn (array $a, array $b) => [$a['priority'], $a['title']] <=> [$b['priority'], $b['title']];
        usort($g['overdue'], $byDue);
        usort($g['today'], $byPrio);
        usort($g['reminders'], fn (array $a, array $b) => [$a['remind_at'], $a['priority']] <=> [$b['remind_at'], $b['priority']]);
        usort($g['soon'], $byDue);
        usort($g['anytime'], $byPrio);
        $focus = array_slice(array_merge($g['overdue'], $g['today'], $g['reminders'], $g['soon'], $g['anytime']), 0, self::FOCUS);

        return $g + ['focus' => $focus];
    }
}
