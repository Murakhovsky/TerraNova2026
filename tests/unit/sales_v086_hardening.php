<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Domains/Sales/Application/Contract/SalesHistoricalIntelligenceHealthReadModelInterface.php';
require_once $root . '/app/Domains/Sales/Application/Service/SalesHistoricalIntelligenceHealthService.php';

use Domains\Sales\Application\Contract\SalesHistoricalIntelligenceHealthReadModelInterface;
use Domains\Sales\Application\Service\SalesHistoricalIntelligenceHealthService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$healthyReadModel = new class implements SalesHistoricalIntelligenceHealthReadModelInterface {
    public function snapshot(string $organizationId): array
    {
        return [
            'stage' => [
                'quality' => ['COMPLETE'=>3,'PARTIAL'=>0,'ESTIMATED'=>2],
                'current_deals'=>5,
                'missing_or_mismatched_open_projection'=>0,
                'dangling_open_projection'=>0,
                'duplicate_open_projection'=>0,
            ],
            'owner' => [
                'quality' => ['COMPLETE'=>2,'PARTIAL'=>1,'ESTIMATED'=>2],
                'assigned_deals'=>5,
                'missing_or_mismatched_open_projection'=>0,
                'dangling_open_projection'=>0,
                'duplicate_open_projection'=>0,
            ],
        ];
    }
};
$healthy = (new SalesHistoricalIntelligenceHealthService($healthyReadModel))->check('tenant-1');
$assert($healthy['status'] === 'HEALTHY', 'ESTIMATED history alone must not be reported as corruption.');
$assert($healthy['rebuild_recommended'] === false, 'Healthy projections must not request rebuild.');

$brokenReadModel = new class implements SalesHistoricalIntelligenceHealthReadModelInterface {
    public function snapshot(string $organizationId): array
    {
        return [
            'stage' => ['missing_or_mismatched_open_projection'=>1,'dangling_open_projection'=>0,'duplicate_open_projection'=>0],
            'owner' => ['missing_or_mismatched_open_projection'=>0,'dangling_open_projection'=>1,'duplicate_open_projection'=>1],
        ];
    }
};
$broken = (new SalesHistoricalIntelligenceHealthService($brokenReadModel))->check('tenant-1');
$assert($broken['status'] === 'DEGRADED', 'Projection integrity failures must degrade health.');
$assert($broken['integrity_failures'] === 3, 'Integrity failures must remain explicit and countable.');
$assert($broken['rebuild_recommended'] === true, 'Broken projections must recommend rebuild.');

$task = file_get_contents($root . '/app/Interfaces/Cli/Task/SalesTask.php');
$migration = file_get_contents($root . '/app/migrations/20260913_000047_sales_v086_hardening.sql');
$runbook = file_get_contents($root . '/docs/sales/EPIC4_RUNBOOK.md');
$module = require $root . '/app/Domains/Sales/module.php';
$assert(is_string($task) && str_contains($task, 'rebuildHistoryAction'), 'Tenant history rebuild CLI is required.');
$assert(is_string($task) && str_contains($task, 'historyHealthAction'), 'History health CLI is required.');
$assert(is_string($migration) && str_contains($migration, 'idx_sales_director_open_v086'), 'Director current-snapshot index is required.');
$assert(is_string($migration) && str_contains($migration, 'idx_sales_stage_current_v086'), 'Stage current-history index is required.');
$assert(is_string($migration) && str_contains($migration, 'idx_sales_owner_current_v086'), 'Owner current-history index is required.');
$assert(is_string($runbook) && str_contains($runbook, 'Never rebuild by deleting canonical `cos_events`'), 'Runbook must protect canonical history.');
$assert(($module['version'] ?? null) === '0.8.6', 'Sales module must be V0.8.6.');
$assert(($module['schema_version'] ?? null) === '0.8.6', 'Sales schema must be V0.8.6 after performance indexes.');

fwrite(STDOUT, "Sales V0.8.6 hardening: OK\n");
