<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccReport;
use App\Accounting\ReportRepo;
use Illuminate\Http\Request;

/** Profit & loss, balance sheet and cash flow. Read-only. */
final class AccountingReportController extends Controller
{
    public function __construct(private readonly ReportRepo $reports) {}

    private static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    /** @return array{0: string, 1: string, 2: string} from, to, preset */
    private static function period(Request $r): array
    {
        $preset = (string) $r->query('preset', 'month');
        if ($preset === 'custom') {
            $from = AccReport::day($r->query('from'), AccReport::range('month', self::today())[0]);
            $to = AccReport::day($r->query('to'), self::today());

            return [$from, $to, 'custom'];
        }
        [$from, $to] = AccReport::range($preset, self::today());

        return [$from, $to, in_array($preset, ['last_month', 'fiscal_year', 'last_fiscal_year', 'calendar_year'], true) ? $preset : 'month'];
    }

    public function profitLoss(Request $request)
    {
        abort_unless($request->user()->can('accounting.view'), 403);
        [$from, $to, $preset] = self::period($request);
        $rep = $from <= $to ? AccReport::profitLoss($this->reports->profitLoss($from, $to)) : AccReport::profitLoss([]);

        return view('accounting.reports.pl', compact('from', 'to', 'preset', 'rep'));
    }

    public function balanceSheet(Request $request)
    {
        abort_unless($request->user()->can('accounting.view'), 403);
        $asof = AccReport::day($request->query('asof'), self::today());

        return view('accounting.reports.balance', ['asof' => $asof, 'rep' => AccReport::balanceSheet($this->reports->balanceSheet($asof))]);
    }

    public function cashFlow(Request $request)
    {
        abort_unless($request->user()->can('accounting.view'), 403);
        [$from, $to, $preset] = self::period($request);
        $rep = $from <= $to ? AccReport::cashFlow($this->reports->cashFlow($from, $to)) : AccReport::cashFlow([]);

        return view('accounting.reports.cashflow', compact('from', 'to', 'preset', 'rep'));
    }
}
