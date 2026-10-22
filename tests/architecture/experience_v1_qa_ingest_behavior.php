<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/symfony/src/Web/Experience/Governance/GoldenQaEvidenceRegistry.php';

use App\Web\Experience\Governance\GoldenQaEvidenceRegistry;

$root = sys_get_temp_dir().'/cos-ux-qa-'.bin2hex(random_bytes(6));
$source = $root.'/source';
mkdir($source, 0775, true);
$pages = [
    'executive-dashboard', 'sales-dashboard', 'sales-today', 'sales-leads',
    'sales-deal', 'sales-pipeline', 'growth-overview', 'property-map',
];
$files = [];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/yQAAAABJRU5ErkJggg==', true);
if ($png === false) throw new RuntimeException('Fixture PNG invalid.');
foreach (['desktop', 'mobile'] as $viewport) {
    foreach ($pages as $page) {
        $prefix = $viewport.'-'.$page;
        file_put_contents($source.'/'.$prefix.'.png', $png.str_repeat("\0", 150));
        file_put_contents($source.'/'.$prefix.'.axe.json', json_encode(['violations' => []]));
        $files[] = [
            'page' => $page,
            'viewport' => $viewport,
            'screenshot' => $prefix.'.png',
            'accessibility' => $prefix.'.axe.json',
        ];
    }
}
$summary = [
    'ok' => true,
    'failures' => [],
    'evidence_provenance' => [
        'source_commit_sha' => str_repeat('a', 40),
        'tested_checkout_sha' => str_repeat('b', 40),
        'workflow_run_id' => '123456',
        'workflow_run_attempt' => '1',
        'observed_at' => '2026-10-22T12:00:00Z',
        'environment' => 'github-actions',
    ],
    'evidence_files' => $files,
];
file_put_contents($source.'/summary.json', json_encode($summary));
$registry = new GoldenQaEvidenceRegistry($root.'/persist/golden-eight.json');

try {
    $expected = [
        'source_commit_sha' => str_repeat('a', 40),
        'tested_checkout_sha' => str_repeat('b', 40),
        'workflow_run_id' => '123456',
        'workflow_run_attempt' => '1',
    ];
    $result = $registry->ingest($source, $expected);
    if (count($result['pages']) !== 8 || $result['human_acceptance'] !== false
        || $registry->forPage('sales.today')['workflow_run_id'] !== '123456') {
        throw new RuntimeException('Golden Eight QA projection failed to reconcile.');
    }

    // Even a syntactically valid, forged run must be rejected.
    $forged = $summary;
    $forged['evidence_provenance']['workflow_run_id'] = '987654';
    file_put_contents($source.'/summary.json', json_encode($forged));
    try {
        $registry->ingest($source, $expected);
        throw new RuntimeException('Forged CI run was incorrectly accepted.');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'incorrectly accepted')) throw $e;
    }
    file_put_contents($source.'/summary.json', json_encode($summary));

    // Rejected evidence must never replace the last verified projection.
    file_put_contents($source.'/mobile-sales-today.axe.json', json_encode(['violations' => [['id' => 'critical']]]));
    try {
        $registry->ingest($source, $expected);
        throw new RuntimeException('Accessibility regression was incorrectly accepted.');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'incorrectly accepted')) throw $e;
    }
    if ($registry->forPage('sales.today')['workflow_run_id'] !== '123456') {
        throw new RuntimeException('Invalid QA run replaced the verified projection.');
    }

    $summary['evidence_provenance']['source_commit_sha'] = 'unknown';
    file_put_contents($source.'/summary.json', json_encode($summary));
    try {
        $registry->ingest($source);
        throw new RuntimeException('Missing SHA provenance was incorrectly accepted.');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'incorrectly accepted')) throw $e;
    }
    echo "Golden Eight QA ingest positive and negative contract OK\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($root);
}
