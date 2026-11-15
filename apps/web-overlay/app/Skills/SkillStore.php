<?php

declare(strict_types=1);

namespace App\Skills;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

/** All skill writes in one place. Every change is a new version row; the skills row always holds the current text. Callers pass the tenant and the owner. */
final class SkillStore
{
    public const MAX_SKILLS = 50;

    public function mine(string $tenantId, string $userId)
    {
        return DB::table('skills')->where('tenant_id', $tenantId)->where('user_id', $userId);
    }

    public function find(string $tenantId, string $userId, string $id): ?object
    {
        return preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1 ? $this->mine($tenantId, $userId)->where('id', $id)->first() : null;
    }

    /** @param array{name: string, description: string, instructions: string} $d @return string|array{error: string} the new id */
    public function create(string $tenantId, string $userId, array $d, string $source): string|array
    {
        if ($this->mine($tenantId, $userId)->where('status', 'active')->count() >= self::MAX_SKILLS) {
            return ['error' => 'You already have '.self::MAX_SKILLS.' skills. Archive one first.'];
        }
        $slug = SkillText::slug($d['name']);
        if ($slug === '') {
            return ['error' => 'Give the skill a name with letters or numbers.'];
        }
        if ($this->mine($tenantId, $userId)->where('status', 'active')->where('slug', $slug)->exists()) {
            return ['error' => "You already have a skill called “/{$slug}”. Pick another name."];
        }
        $id = CreateWorkspace::newId();
        DB::transaction(function () use ($tenantId, $userId, $d, $source, $id, $slug): void {
            DB::table('skills')->insert(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'slug' => $slug, 'name' => $d['name'], 'description' => $d['description'], 'instructions' => $d['instructions'], 'source' => $source]);
            $this->version($id, $tenantId, 1, $d, 'current', $source === 'learned' ? 'Learned from a task' : 'Created by hand');
        });

        return $id;
    }

    /** Saves new text as the next version (the slug stays, so "/slug" keeps working). @param array{name: string, description: string, instructions: string} $d */
    public function revise(object $skill, array $d, string $note): void
    {
        DB::transaction(function () use ($skill, $d, $note): void {
            $v = $this->nextVersion($skill->id);
            DB::table('skill_versions')->where('skill_id', $skill->id)->where('state', 'current')->update(['state' => 'old']);
            DB::table('skills')->where('id', $skill->id)->update(['name' => $d['name'], 'description' => $d['description'], 'instructions' => $d['instructions'], 'version' => $v, 'updated_at' => now()]);
            $this->version($skill->id, $skill->tenant_id, $v, $d, 'current', $note);
        });
    }

    /** A model suggestion waiting for the owner. Replaces any earlier open suggestion. @param array{name: string, description: string, instructions: string} $d */
    public function propose(object $skill, array $d, string $note): void
    {
        DB::transaction(function () use ($skill, $d, $note): void {
            DB::table('skill_versions')->where('skill_id', $skill->id)->where('state', 'proposed')->update(['state' => 'rejected']);
            $this->version($skill->id, $skill->tenant_id, $this->nextVersion($skill->id), $d, 'proposed', $note);
        });
    }

    public function approve(object $skill, string $versionId): bool
    {
        $p = DB::table('skill_versions')->where('id', $versionId)->where('skill_id', $skill->id)->where('state', 'proposed')->first();
        if ($p === null) {
            return false;
        }
        DB::transaction(function () use ($skill, $p): void {
            DB::table('skill_versions')->where('skill_id', $skill->id)->where('state', 'current')->update(['state' => 'old']);
            DB::table('skill_versions')->where('id', $p->id)->update(['state' => 'current']);
            DB::table('skills')->where('id', $skill->id)->update(['name' => $p->name, 'description' => $p->description, 'instructions' => $p->instructions, 'version' => $p->version, 'updated_at' => now()]);
        });

        return true;
    }

    public function reject(object $skill, string $versionId): void
    {
        DB::table('skill_versions')->where('id', $versionId)->where('skill_id', $skill->id)->where('state', 'proposed')->update(['state' => 'rejected']);
    }

    /** Going back copies an old version forward as a new one, so history is never lost. */
    public function rollback(object $skill, string $versionId): bool
    {
        $o = DB::table('skill_versions')->where('id', $versionId)->where('skill_id', $skill->id)->whereIn('state', ['old', 'rejected'])->first();
        if ($o === null) {
            return false;
        }
        $this->revise($skill, ['name' => $o->name, 'description' => $o->description, 'instructions' => $o->instructions], 'Restored version '.$o->version);

        return true;
    }

    private function nextVersion(string $skillId): int
    {
        return (int) DB::table('skill_versions')->where('skill_id', $skillId)->max('version') + 1;
    }

    /** @param array{name: string, description: string, instructions: string} $d */
    private function version(string $skillId, string $tenantId, int $n, array $d, string $state, string $note): void
    {
        DB::table('skill_versions')->insert(['id' => CreateWorkspace::newId(), 'skill_id' => $skillId, 'tenant_id' => $tenantId, 'version' => $n, 'name' => $d['name'], 'description' => $d['description'], 'instructions' => $d['instructions'], 'state' => $state, 'note' => mb_substr($note, 0, 290)]);
    }
}
