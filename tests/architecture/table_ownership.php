<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\TableOwnership;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$violations = [];
$domainsRoot = $root . '/app/Domains';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainsRoot));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('~/Domains/([^/]+)/Infrastructure/Persistence/MySql/~', $path, $domain)) {
        continue;
    }
    $source = file_get_contents($file->getPathname()) ?: '';
    preg_match_all('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z][a-z0-9_]*)`?/i', $source, $matches);
    foreach (array_unique(array_map('strtolower', $matches[1] ?? [])) as $table) {
        $owner = TableOwnership::ownerOf($table);
        if ($owner !== null && $owner !== $domain[1]) {
            $violations[] = $domain[1] . ' writes ' . $table . ' owned by ' . $owner . ' in ' . $path;
        }
    }
}

if ($violations) {
    fwrite(STDERR, implode("\n", $violations) . "\n");
    exit(1);
}

// One owner for federation compatibility and canonical execution tables.
// An earlier inventory incorrectly assigned Goal/Experience rows to CapitalMarkets.
foreach ([
    'cos_federation_goals', 'cos_federation_goal_specs', 'cos_federation_plans',
    'cos_federation_runs', 'cos_federation_steps', 'cos_federation_evaluations',
    'cos_experience_profiles', 'cos_workspace_experience_states',
    'cos_workflow_runs', 'cos_workflow_steps',
    'cos_workflow_recovery_decisions', 'cos_workflow_recovery_events',
] as $table) {
    if (TableOwnership::ownerOf($table) !== 'Platform') {
        throw new RuntimeException('Canonical Platform owner mismatch: ' . $table);
    }
}
if (TableOwnership::ownerOf('tn_capital_market_instruments') !== 'CapitalMarkets') {
    throw new RuntimeException('CapitalMarkets business table ownership has drifted.');
}

echo "Table ownership passed: Domain SQL ownership and federated Platform Run/Goal/Experience boundaries.\n";
