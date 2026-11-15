<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\ManageInvitations;
use App\Exceptions\InvitationException;
use App\Notifications\InvitationNotification;
use App\Support\Entitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

final class InvitationController extends Controller
{
    private const MESSAGES = [
        'invitation_invalid' => 'This invitation link is not valid.',
        'invitation_used' => 'This invitation has already been used.',
        'invitation_expired' => 'The invitation has expired. Ask for a new invitation.',
        'invitation_email_mismatch' => 'This invitation is for a different email address. Log in to the correct account.',
        'tenant_unavailable' => 'This workspace is not available now.',
        'user_unavailable' => 'Your account is not usable now.',
        'user_email_unverified' => 'First verify your email.',
        'seat_limit' => 'All seats in this workspace are full — ask the owner to add seats.',
        'license_expired' => 'This workspace’s license has expired — ask the owner to renew.',
    ];

    public function __construct(
        private readonly ManageInvitations $invitations,
        private readonly TenantContext $context,
    ) {}

    /** Inside a tenant context (ResolveTenant). */
    public function store(Request $request)
    {
        abort_unless($request->user()->can('members.invite'), 403);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(TenantRoles::INVITABLE)],
        ]);

        $tenantId = $this->context->idOrFail();

        // Plan limit: active members + other pending invitations (re-inviting the same email is free).
        $used = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('status', 'active')->count()
            + DB::table('invitations')->whereNull('accepted_at')->where('expires_at', '>', now())
                ->where('email', '<>', mb_strtolower(trim($data['email'])))->count();
        if (! app(Entitlements::class)->allowsMore('limit.members', $used)) {
            return back()->withErrors(['email' => 'Your plan’s member limit is full. Upgrade the plan.'])->withInput();
        }

        if (($blocked = app(\App\License\LicenseService::class)->inviteBlockedReason($tenantId)) !== null) {
            return back()->withErrors(['email' => $blocked])->withInput();
        }

        $token = $this->invitations->create($data['email'], $data['role'], $request->user());
        $name = (string) DB::table('tenants')->where('id', $tenantId)->value('name');

        Notification::route('mail', $data['email'])
            ->notify(new InvitationNotification($name, $data['role'], route('invitations.show', ['token' => $token])));

        return back()->with('status', 'The invitation was sent.');
    }

    /** Public: the invitee opens the emailed link, possibly not logged in. */
    public function show(Request $request, string $token)
    {
        $invitation = $this->invitations->preview($token);
        abort_if($invitation === null, 404);

        if ($request->user() === null) {
            // After login/registration Fortify sends the user back here.
            $request->session()->put('url.intended', $request->url());
        }

        return view('invitations.show', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token)
    {
        $user = $request->user();
        try {
            $result = $this->invitations->accept($user, $token);
        } catch (InvitationException $e) {
            return back()->withErrors(['invitation' => self::MESSAGES[$e->reason] ?? 'The invitation could not be accepted.']);
        }

        TenantRoles::assign($user, $result['tenant_id'], $result['role']);
        $request->session()->put('tenant_id', $result['tenant_id']);

        return redirect()->route('dashboard')->with('status', 'Workspace-Joining is complete.');
    }
}
