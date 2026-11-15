<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crm\CrmStore;
use App\Crm\CsvImporter;
use App\Crm\LeadCsv;
use App\Leads\LeadStore;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** CSV import and export of leads. */
final class LeadCsvController extends Controller
{
    public const MAX_ROWS = 1000;

    public const EXPORT_ROWS = 5000;

    public function form(Request $request)
    {
        abort_unless($request->user()->can('leads.manage'), 403);

        return view('leads.import', ['max' => self::MAX_ROWS]);
    }

    public function import(Request $request, TenantContext $tenant, LeadStore $leads, CrmStore $crm)
    {
        abort_unless($request->user()->can('leads.manage'), 403);
        $request->validate(['csv' => ['required', 'file', 'max:'.config('leads.csv_max_kb'), 'extensions:csv,txt']]);
        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return back()->withErrors(['csv' => 'CSV The file must be UTF-8 (from Excel "CSV UTF-8" save as).']);
        }
        $parsed = LeadCsv::parse($raw, self::MAX_ROWS);
        if ($parsed['error'] === 'no_website_column') {
            return back()->withErrors(['csv' => 'In the first row "website" (or url/domain) column is needed.']);
        }
        if ($parsed['rows'] === []) {
            return back()->withErrors(['csv' => 'No valid rows to import were found.']);
        }
        $r = (new CsvImporter($leads, $crm))->import($tenant->idOrFail(), (string) $request->user()->getAuthIdentifier(), $parsed);

        $msg = "New leads {$r['created']}, already existed {$r['existing']}, contacts added {$r['contacts_added']} items";
        if ($r['contacts_skipped'] > 0) {
            $msg .= " (skipped/duplicate {$r['contacts_skipped']})";
        }
        if ($parsed['duplicates'] > 0) {
            $msg .= ", duplicates inside the file {$parsed['duplicates']} skipped";
        }
        if ($parsed['rejected'] !== []) {
            $msg .= ', Wrong address: row '.implode(', ', array_column(array_slice($parsed['rejected'], 0, 10), 'line'));
        }
        if ($parsed['truncated']) {
            $msg .= ', First '.self::MAX_ROWS.' were taken';
        }

        return redirect()->route('leads.index')->with('status', $msg.'.');
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $rows = DB::select("select c.name, c.domain, l.stage, l.score, array_to_string(l.tags, ';') as tags, l.next_follow_up_at, l.source, l.created_at,
                (select string_agg(k.email, '; ' order by k.email) from contacts k where k.company_id = c.id and k.email is not null) as emails,
                (select string_agg(k.phone_e164, '; ' order by k.phone_e164) from contacts k where k.company_id = c.id and k.phone_e164 is not null) as phones
            from leads l join companies c on c.id = l.company_id where l.deleted_at is null order by l.score desc, l.created_at desc limit ?", [self::EXPORT_ROWS]);
        $out = [['name', 'website', 'email', 'phone', 'stage', 'score', 'tags', 'follow_up', 'source', 'created']];
        foreach ($rows as $r) {
            $out[] = [$r->name, $r->domain, (string) $r->emails, (string) $r->phones, $r->stage, (string) $r->score,
                (string) $r->tags,
                $r->next_follow_up_at === null ? '' : substr((string) $r->next_follow_up_at, 0, 10), $r->source, substr((string) $r->created_at, 0, 10)];
        }
        $csv = LeadCsv::toCsv($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="leads-'.now()->format('Ymd').'.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
