<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crm\CrmMessages;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Lead list and a lead's detail page. Writes on a lead are in LeadCrmController. */
final class LeadController extends Controller
{
    public const STAGES = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'proposal' => 'Proposals', 'won' => 'Won', 'lost' => 'Lost'];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'stage' => ['nullable', 'in:'.implode(',', array_keys(self::STAGES))], 'min' => ['nullable', 'integer', 'between:0,100']]);

        $w = ['l.deleted_at is null'];
        $b = [];
        if (! empty($q['q'])) {
            $w[] = '(c.name ilike ? or c.domain ilike ?)';
            $like = '%'.addcslashes($q['q'], '\\%_').'%';
            array_push($b, $like, $like);
        }
        if (! empty($q['stage'])) {
            $w[] = 'l.stage = ?';
            $b[] = $q['stage'];
        }
        if (isset($q['min']) && $q['min'] !== null) {
            $w[] = 'l.score >= ?';
            $b[] = (int) $q['min'];
        }
        $page = max(1, (int) $request->query('page', 1));
        $rows = DB::select('select l.id, l.stage, l.score, l.created_at, c.name, c.domain, c.analysis, array_to_string(l.tags, chr(44)) as tags,
                (select count(*) from contacts k where k.company_id = c.id) as contacts
            from leads l join companies c on c.id = l.company_id where '.implode(' and ', $w).' order by l.score desc, l.created_at desc limit 31 offset ?', [...$b, ($page - 1) * 30]);

        return view('leads.index', ['leads' => array_slice($rows, 0, 30), 'more' => count($rows) > 30, 'page' => $page, 'stages' => self::STAGES, 'filters' => $q]);
    }

    public function show(Request $request, TenantContext $tenant, string $id)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $lead = DB::selectOne('select l.id, l.stage, l.score, l.source, array_to_string(l.tags, chr(44)) as tags, l.next_follow_up_at, l.created_at, c.id as company_id, c.name, c.domain, c.website, c.description, c.address, c.analysis, c.analyzed_at
            from leads l join companies c on c.id = l.company_id where l.id = ? and l.deleted_at is null', [$id]) ?? abort(404);

        return view('leads.show', [
            'lead' => $lead,
            'analysis' => json_decode((string) $lead->analysis, true) ?: [],
            'contacts' => DB::select('select email, email_status, phone_e164, phone_type, source_url from contacts where company_id = ? order by email nulls last, phone_e164', [$lead->company_id]),
            'stages' => self::STAGES,
            'canManage' => $request->user()->can('leads.manage'),
            'canPropose' => $request->user()->can('proposals.manage'),
            'proposals' => DB::select('select id, title, status from proposals where lead_id = ? and deleted_at is null order by updated_at desc limit 20', [$id]),
            'tz' => CrmMessages::zone($tenant->idOrFail()),
            'tagList' => $lead->tags === '' ? [] : explode(',', $lead->tags),
            'tasks' => DB::select('select id, title, due_at, done_at from crm_tasks where lead_id = ? order by done_at nulls first, due_at nulls last, created_at limit 50', [$id]),
            'activities' => DB::select('select a.kind, a.body, a.meta, a.created_at, u.name as user_name from lead_activities a left join users u on u.id = a.user_id
                where a.lead_id = ? order by a.created_at desc limit 100', [$id]),
        ]);
    }
}
