<?php

use App\Pc\PcService;

function auditRow(array $over = []): array
{
    return array_merge(['t' => 1700000000, 'task' => 't1', 'step' => 's1', 'event' => 'act', 'detail' => 'ok', 'prev' => str_repeat('0', 64), 'h' => str_repeat('a', 64)], $over);
}

it('accepts a well-formed audit batch and keeps start and rows', function () {
    $b = PcService::auditBatchFrom(['start' => 0, 'rows' => [auditRow(), auditRow(['t' => 5])]]);
    expect($b['start'])->toBe(0);
    expect(count($b['rows']))->toBe(2);
});

it('refuses batches that are empty, too big, or the wrong shape', function () {
    $bad = [
        [], ['start' => 0], ['start' => 0, 'rows' => []], ['start' => -1, 'rows' => [auditRow()]], ['start' => '0', 'rows' => [auditRow()]], ['start' => 0, 'rows' => 'x'],
        ['start' => 0, 'rows' => ['a' => auditRow()]], ['start' => 0, 'rows' => array_fill(0, 201, auditRow())], ['start' => 0, 'rows' => [5]],
        ['start' => 0, 'rows' => [auditRow(['extra' => 1])]], ['start' => 0, 'rows' => [auditRow(['t' => '1'])]], ['start' => 0, 'rows' => [auditRow(['t' => -3])]],
        ['start' => 0, 'rows' => [auditRow(['detail' => null])]], ['start' => 0, 'rows' => [auditRow(['h' => 9])]],
    ];
    foreach ($bad as $i => $in) {
        expect(PcService::auditBatchFrom($in))->toBeNull();
    }
    $missing = auditRow();
    unset($missing['step']);
    expect(PcService::auditBatchFrom(['start' => 0, 'rows' => [$missing]]))->toBeNull();
    expect(PcService::auditBatchFrom(['start' => 0, 'rows' => array_fill(0, 200, auditRow())]))->not->toBeNull();
});

it('has the two routes behind the device token and a controller that maps the database answers', function () {
    $routes = file_get_contents(__DIR__.'/../../routes/api_pc.php');
    expect(str_contains($routes, "Route::get('audit/head'"))->toBeTrue();
    expect(str_contains($routes, "Route::post('audit/sync'"))->toBeTrue();
    expect(strpos($routes, 'audit/head') > strpos($routes, 'PcAgentAuth'))->toBeTrue();
    $c = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/Pc/AgentController.php');
    expect(str_contains($c, "'seq_mismatch', 'chain_broken' => 409"))->toBeTrue();
    expect(str_contains($c, '300_000'))->toBeTrue();
});

it('the repository contract and the SQL speak the same names', function () {
    $sql = file_get_contents(__DIR__.'/../../database/sql/phase37a.up.sql');
    $pg = file_get_contents(__DIR__.'/../../app/Pc/PgPcRepo.php');
    foreach (['pc_audit_head', 'pc_audit_ingest'] as $fn) {
        expect(str_contains($sql, "FUNCTION {$fn}("))->toBeTrue();
        expect(str_contains($pg, "select {$fn}("))->toBeTrue();
    }
});
