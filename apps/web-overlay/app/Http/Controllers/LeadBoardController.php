<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crm\CrmMessages;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Pipeline board (one column per stage) and the "what do I have to do" page. Read only; writes go through LeadCrmController. */
final class LeadBoardController extends Controller
{
    private const PER_COLUMN = 30;

    public function board(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $tag = mb_strtolower(trim((string) $request->query('tag', '')));
        $rows = DB::select("select * from (
                select l.id, l.stage, l.score, array_to_string(l.tags, ',') as tags, l.next_follow_up_at, c.name, c.domain,
                       (select count(*) from crm_tasks t where t.lead_id = l.id and t.done_at is null) as open_tasks,
                       row_number() over (partition by l.stage order by l.score desc, l.created_at desc) as rn,
                       count(*) over (partition by l.stage) as stage_total
                  from leads l join companies c on c.id = l.company_id
                 where l.deleted_at is null and (?::text is null or ?::text = any(l.tags))
            ) x where rn <= ? order by stage, rn", [$tag === '' ? null : $tag, $tag === '' ? null : $tag, self::PER_COLUMN]);
        $cols = array_fill_keys(array_keys(LeadController::STAGES), ['total' => 0, 'cards' => []]);
        foreach ($rows as $r) {
            $cols[$r->stage]['total'] = (int) $r->stage_total;
            $cols[$r->stage]['cards'][] = $r;
        }

        return view('leads.board', [
            'cols' => $cols, 'stages' => LeadController::STAGES, 'tag' => $tag, 'perColumn' => self::PER_COLUMN,
            'canManage' => $request->user()->can('leads.manage'), 'tz' => CrmMessages::zone($tenant->idOrFail()),
            'tags' => DB::select('select t as tag, count(*) as n from leads l, unnest(l.tags) t where l.deleted_at is null group by t order by n desc, t limit 30'),
        ]);
    }

    public function tasks(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $mine = $request->boolean('mine');
        $uid = (string) $request->user()->getAuthIdentifier();

        return view('leads.tasks', [
            'tasks' => DB::select('select t.id, t.title, t.due_at, t.lead_id, c.name from crm_tasks t
                    join leads l on l.id = t.lead_id join companies c on c.id = l.company_id
                   where t.done_at is null and l.deleted_at is null and (?::text is null or t.assignee_id = ?::text)
                   order by t.due_at nulls last, t.created_at limit 200', $mine ? [$uid, $uid] : [null, null]),
            'followUps' => DB::select("select l.id, l.next_follow_up_at, c.name from leads l join companies c on c.id = l.company_id
                   where l.deleted_at is null and l.next_follow_up_at is not null and l.next_follow_up_at < now() + interval '14 days'
                   order by l.next_follow_up_at limit 100"),
            'mine' => $mine, 'canManage' => $request->user()->can('leads.manage'), 'tz' => CrmMessages::zone($tenant->idOrFail()),
        ]);
    }
}
