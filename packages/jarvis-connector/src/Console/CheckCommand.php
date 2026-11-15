<?php

declare(strict_types=1);

namespace Jarvis\Connector\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Jarvis\Connector\ProductStore;

/** Checks the column map against the real database, so mistakes show up here instead of as errors in JARVIS. */
final class CheckCommand extends Command
{
    protected $signature = 'jarvis-connector:check';

    protected $description = 'Check the JARVIS connector settings against your database';

    public function handle(): int
    {
        $bad = 0;
        if (strlen((string) config('jarvis-connector.secret')) < 32) {
            $this->error('JARVIS_CONNECTOR_SECRET is missing or shorter than 32 characters — run jarvis-connector:secret.');
            $bad++;
        }
        if (config('jarvis-connector.store')) {
            $this->info('Custom store class in use: '.config('jarvis-connector.store').' (column map not checked).');

            return $bad ? self::FAILURE : self::SUCCESS;
        }
        $c = (array) config('jarvis-connector.database');
        $conn = config('jarvis-connector.connection');
        $schema = Schema::connection($conn);
        $need = [(string) ($c['table'] ?? '') => array_filter([$c['id'] ?? 'id', ...array_values((array) ($c['columns'] ?? [])), $c['touch'] ?? null, $c['soft_delete'] ?? null, ...array_keys((array) ($c['insert_defaults'] ?? []))])];
        if (! empty($c['variants'])) {
            $v = $c['variants'];
            $need[(string) $v['table']] = array_filter([$v['id'], $v['product_id'], $v['sku'] ?? null, $v['price'] ?? null, $v['stock'] ?? null, $v['options'] ?? null]);
        }
        foreach ($need as $table => $cols) {
            if (! $schema->hasTable($table)) {
                $this->error("Table '$table' does not exist.");
                $bad++;

                continue;
            }
            foreach ($cols as $col) {
                if (! $schema->hasColumn($table, $col)) {
                    $this->error("Column '$table.$col' does not exist.");
                    $bad++;
                }
            }
        }
        try {
            $r = app(ProductStore::class)->page(null, 1);
            $this->info('Read test OK: '.count($r['items']).' product(s) on the first page'.(isset($r['items'][0]) ? ' — first: '.$r['items'][0]['title'] : '').'.');
        } catch (\Throwable $e) {
            $this->error('Read test failed: '.$e->getMessage());
            $bad++;
        }
        $this->line($bad === 0 ? 'All good. Now connect this shop in JARVIS ONE.' : "$bad problem(s) — fix config/jarvis-connector.php and run this again.");

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
