<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAudit;
use App\Actions\Tenancy\CreateWorkspace;
use App\Reseller\ResellerService;
use Illuminate\Http\Request;

/** Platform admins approve resellers, set the commission rate (for future customers), suspend, and record payouts. */
final class ResellerAdminController extends AdminController
{
    private const ERR = ['bad_input' => 'The input is not valid.', 'no_user' => 'There is no user with this email.', 'already' => 'This person is already a reseller.', 'code_taken' => 'The code already exists.',
        'not_found' => 'Not found.', 'exceeds_due' => 'You cannot pay more than what is owed.'];

    public function index()
    {
        $this->allow('resellers.manage');
        $rows = json_decode((string) $this->db()->selectOne('select reseller_admin_overview() as r')->r, true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as &$r) {
            ksort($r['money']);
            $r['lines'] = [];
            foreach ($r['money'] as $cur => $m) {
                $r['lines'][] = ['currency' => (string) $cur, 'commission' => ResellerService::fmt((int) $m['commission']), 'paid_out' => ResellerService::fmt((int) $m['paid_out']), 'due' => ResellerService::fmt((int) $m['due'])];
            }
        }

        return view('admin.resellers.index', ['resellers' => $rows]);
    }

    public function store(Request $request)
    {
        $this->allow('resellers.manage');
        $d = $request->validate(['email' => ['required', 'email', 'max:254'], 'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'regex:/\A[A-Za-z0-9]{6,12}\z/'], 'rate' => ['required', 'numeric', 'min:0', 'max:50']]);
        $user = $this->db()->table('users')->whereRaw('lower(email) = ?', [mb_strtolower($d['email'])])->value('id');
        if ($user === null) {
            return back()->withErrors(['email' => self::ERR['no_user']])->withInput();
        }
        $r = (string) $this->db()->selectOne('select reseller_create(?, ?, ?, ?, ?) as r', [CreateWorkspace::newId(), trim((string) $user), $d['name'], $d['code'], (int) round((float) $d['rate'] * 100)])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['email' => self::ERR[$r] ?? self::ERR['bad_input']])->withInput();
        }
        AdminAudit::record('admin.reseller.created', 'reseller', null, null, ['email' => $d['email'], 'code' => $d['code'], 'rate' => $d['rate']]);

        return back()->with('status', 'The reseller was added.');
    }

    public function update(Request $request, string $id)
    {
        $this->allow('resellers.manage');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['status' => ['required', 'in:active,suspended'], 'rate' => ['required', 'numeric', 'min:0', 'max:50']]);
        $r = (string) $this->db()->selectOne('select reseller_update(?, ?, ?) as r', [$id, $d['status'], (int) round((float) $d['rate'] * 100)])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['reseller' => self::ERR[$r] ?? self::ERR['bad_input']]);
        }
        AdminAudit::record('admin.reseller.updated', 'reseller', $id, null, $d);

        return back()->with('status', 'Updated (the new rate applies only to future customers).');
    }

    public function payout(Request $request, string $id)
    {
        $this->allow('resellers.manage');
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $d = $request->validate(['currency' => ['required', 'regex:/\A[A-Z]{3}\z/'], 'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'note' => ['nullable', 'string', 'max:200']]);
        $r = (string) $this->db()->selectOne('select reseller_payout(?, ?, ?, ?, ?) as r', [CreateWorkspace::newId(), $id, $d['currency'], (int) round((float) $d['amount'] * 100), (string) ($d['note'] ?? '')])->r;
        if ($r !== 'ok') {
            return back()->withErrors(['reseller' => self::ERR[$r] ?? self::ERR['bad_input']]);
        }
        AdminAudit::record('admin.reseller.payout', 'reseller', $id, null, ['currency' => $d['currency'], 'amount' => $d['amount']]);

        return back()->with('status', 'The payout was recorded.');
    }
}
