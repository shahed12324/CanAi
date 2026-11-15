<?php

declare(strict_types=1);

namespace App\Productivity;

use Illuminate\Support\Facades\DB;

/** Reads are tenant-scoped by RLS and also filtered to the person here (RLS alone would show a colleague's tasks). Writes go through functions. */
final class PgTaskRepo implements TaskRepo
{
    private static function b(?bool $v): ?string
    {
        return $v === null ? null : ($v ? 'true' : 'false');
    }

    public function tasks(string $userId, int $doneLimit = 30): array
    {
        $rows = DB::select("(select id, title, details, priority, due_on, remind_at, reminded_at, status, done_at, 0 as grp from tasks where user_id = ? and status = 'open')
            union all (select id, title, details, priority, due_on, remind_at, reminded_at, status, done_at, 1 as grp from tasks where user_id = ? and status = 'done' order by done_at desc limit ?)
            order by grp, due_on nulls last, priority, title", [$userId, $userId, max(0, min(200, $doneLimit))]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'details' => (string) $r->details, 'priority' => (int) $r->priority,
            'due_on' => $r->due_on === null ? null : (string) $r->due_on, 'remind_at' => $r->remind_at === null ? null : (string) $r->remind_at, 'reminded' => $r->reminded_at !== null,
            'status' => (string) $r->status, 'done_at' => $r->done_at === null ? null : (string) $r->done_at], $rows);
    }

    public function add(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string
    {
        return (string) DB::selectOne('select task_add(?, ?, ?, ?, ?, ?, ?::date, ?::timestamptz) as r', [$id, $tenantId, $userId, $title, $details, $priority, $due, $remind])->r;
    }

    public function edit(string $tenantId, string $userId, string $id, string $title, string $details, int $priority, ?string $due, ?string $remind): string
    {
        return (string) DB::selectOne('select task_edit(?, ?, ?, ?, ?, ?, ?::date, ?::timestamptz) as r', [$id, $tenantId, $userId, $title, $details, $priority, $due, $remind])->r;
    }

    public function setDone(string $tenantId, string $userId, string $id, bool $done): string
    {
        return (string) DB::selectOne('select task_set_done(?, ?, ?, ?::boolean) as r', [$id, $tenantId, $userId, self::b($done)])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select task_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function clearDone(string $tenantId, string $userId): int
    {
        return (int) DB::selectOne('select task_clear_done(?, ?) as r', [$tenantId, $userId])->r;
    }

    public function notes(string $userId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'title' => (string) $r->title, 'body' => (string) $r->body, 'pinned' => (bool) $r->pinned, 'updated_at' => (string) $r->updated_at],
            DB::select('select id, title, body, pinned, updated_at from notes where user_id = ? order by pinned desc, updated_at desc limit 1000', [$userId]));
    }

    public function saveNote(string $tenantId, string $userId, string $id, string $title, string $body, ?bool $pinned, bool $isNew): string
    {
        return (string) DB::selectOne('select note_save(?, ?, ?, ?, ?, ?::boolean, ?::boolean) as r', [$id, $tenantId, $userId, $title, $body, self::b($pinned), self::b($isNew)])->r;
    }

    public function deleteNote(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select note_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }
}
