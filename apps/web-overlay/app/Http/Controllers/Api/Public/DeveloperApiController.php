<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Credits\CreditService;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowRepo;
use App\Workflow\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The public developer API (`/api/public/v1`).  Everything here acts as the key's person in the key's workspace, and only within the key's scopes (ApiKeyAuth).
 * A key can start the person's own enabled workflows; it can never approve a run, delete anything or change a key.
 */
final class DeveloperApiController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly WorkflowRepo $workflows, private readonly WorkflowService $service) {}

    private static function user(Request $r): string
    {
        return (string) $r->attributes->get('api_user');
    }

    /** The machine-readable description of this API (no key needed; it holds no data). */
    public function openapi(): \Illuminate\Http\Response
    {
        return response((string) file_get_contents(resource_path('openapi/public-v1.json')), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = DB::table('users')->where('id', self::user($request))->first(['id', 'name']);
        $t = DB::table('tenants')->where('id', $this->tenant->idOrFail())->first(['id', 'name']);

        return response()->json(['data' => ['user' => ['id' => trim((string) ($u->id ?? '')), 'name' => (string) ($u->name ?? '')], 'workspace' => ['id' => trim((string) ($t->id ?? '')), 'name' => (string) ($t->name ?? '')]]]);
    }

    public function credits(CreditService $credits): JsonResponse
    {
        $w = $credits->wallet($this->tenant->idOrFail());

        return response()->json(['data' => ['balance' => $w === null ? '0' : (string) $w->balance, 'reserved' => $w === null ? '0' : (string) $w->reserved, 'available' => $w === null ? '0' : (string) $w->available]]);
    }

    public function workflows(Request $request): JsonResponse
    {
        $out = [];
        foreach ($this->workflows->workflows(self::user($request)) as $w) {
            $out[] = ['id' => $w['id'], 'name' => $w['name'], 'enabled' => $w['enabled'], 'inputs' => array_column(WorkflowDefinition::inputs($w['definition']), 'key')];
        }

        return response()->json(['data' => $out]);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $inputs = $request->input('inputs', []);
        if (! is_array($inputs)) {
            return response()->json(['error' => 'invalid_inputs', 'message' => '"inputs" must be an object of text values.'], 422);
        }
        $clean = [];
        foreach ($inputs as $k => $v) {
            if (! is_string($k) || ! is_scalar($v)) {
                return response()->json(['error' => 'invalid_inputs', 'message' => '"inputs" must be an object of text values.'], 422);
            }
            $clean[$k] = (string) $v;
        }
        $dry = $request->input('dry_run', false) === true;
        $r = $this->service->start($this->tenant->idOrFail(), self::user($request), $id, $clean, $dry);
        if ($r['status'] === 'ok' && $dry) {
            return response()->json(['data' => ['dry_run' => true, 'ok' => true]]);
        }
        if ($r['status'] === 'ok') {
            return response()->json(['data' => ['run_id' => $r['run_id'], 'status' => 'queued']], 202);
        }
        [$code, $http] = match ($r['status']) {
            'not_found' => ['not_found', 404],
            'disabled' => ['disabled', 409],
            'busy' => ['busy', 429],
            'missing_input' => ['missing_input', 422],
            'too_long' => ['input_too_long', 422],
            default => ['failed', 422],
        };

        return response()->json(['error' => $code], $http);
    }

    public function runStatus(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-z]{26}\z/', $id) === 1, 404);
        $run = $this->workflows->run($id);
        abort_if($run === null || $run['user_id'] !== self::user($request), 404);

        return response()->json(['data' => ['run_id' => $id, 'status' => $run['status'], 'spent' => $run['spent'], 'max_credits' => $run['max_credits']]]);
    }
}
