<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only QA projection for Page Contracts. CI evidence never grants human acceptance.
 * The canonical Page Contract YAML remains the source of declared product intent.
 */
final readonly class GoldenQaEvidenceRegistry
{
    private const PAGES = [
        'executive-dashboard' => 'core.executive.dashboard',
        'sales-dashboard' => 'sales.dashboard',
        'sales-today' => 'sales.today',
        'sales-leads' => 'sales.leads.collection',
        'sales-deal' => 'sales.deal.workspace',
        'sales-pipeline' => 'sales.pipeline',
        'growth-overview' => 'growth.overview',
        'property-map' => 'property.map',
    ];
    private const VIEWPORTS = ['desktop', 'mobile'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/experience/qa/golden-eight.json')]
        private string $projectionPath,
    ) {}

    /** @return array<string,mixed> */
    public function ingest(string $sourceDirectory): array
    {
        $sourceDirectory = rtrim($sourceDirectory, '/');
        $summaryFile = $sourceDirectory.'/summary.json';
        $summary = $this->decodeFile($summaryFile);
        if (($summary['ok'] ?? null) !== true || ($summary['failures'] ?? null) !== []) {
            throw new RuntimeException('Golden Eight QA summary is not a clean PASS.');
        }

        $provenance = $summary['evidence_provenance'] ?? null;
        if (!is_array($provenance)) {
            throw new RuntimeException('Golden Eight evidence provenance is missing.');
        }
        foreach (['source_commit_sha', 'tested_checkout_sha'] as $field) {
            if (!preg_match('/^[a-f0-9]{40}$/i', (string) ($provenance[$field] ?? ''))) {
                throw new RuntimeException('Invalid Golden Eight provenance: '.$field);
            }
        }
        if (!preg_match('/^[1-9][0-9]*$/', (string) ($provenance['workflow_run_id'] ?? ''))
            || !preg_match('/^[1-9][0-9]*$/', (string) ($provenance['workflow_run_attempt'] ?? ''))
            || !is_string($provenance['observed_at'] ?? null)
            || strtotime($provenance['observed_at']) === false) {
            throw new RuntimeException('Golden Eight evidence needs a real CI run, attempt and timestamp.');
        }
        if (($provenance['environment'] ?? null) !== 'github-actions') {
            throw new RuntimeException('Local evidence is not publishable as verified CI evidence.');
        }

        $files = $summary['evidence_files'] ?? null;
        if (!is_array($files) || count($files) !== count(self::PAGES) * count(self::VIEWPORTS)) {
            throw new RuntimeException('Golden Eight evidence must contain exactly 16 page/viewport observations.');
        }

        $pages = [];
        $seen = [];
        foreach ($files as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Malformed Golden Eight evidence entry.');
            }
            $target = (string) ($entry['page'] ?? '');
            $viewport = (string) ($entry['viewport'] ?? '');
            $key = $target.'/'.$viewport;
            if (!isset(self::PAGES[$target]) || !in_array($viewport, self::VIEWPORTS, true) || isset($seen[$key])) {
                throw new RuntimeException('Unknown or duplicate Golden Eight observation: '.$key);
            }
            $seen[$key] = true;
            $screenshot = (string) ($entry['screenshot'] ?? '');
            $axeFile = (string) ($entry['accessibility'] ?? '');
            if ($screenshot !== $viewport.'-'.$target.'.png' || $axeFile !== $viewport.'-'.$target.'.axe.json') {
                throw new RuntimeException('Unexpected Golden Eight evidence filename: '.$key);
            }
            $png = $sourceDirectory.'/'.$screenshot;
            if (!is_file($png) || filesize($png) < 100 || file_get_contents($png, false, null, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new RuntimeException('Missing or invalid screenshot: '.$screenshot);
            }
            $axe = $this->decodeFile($sourceDirectory.'/'.$axeFile);
            if (!isset($axe['violations']) || !is_array($axe['violations']) || $axe['violations'] !== []) {
                throw new RuntimeException('Accessibility evidence is missing or contains violations: '.$axeFile);
            }
            $pageId = self::PAGES[$target];
            $pages[$pageId]['page_id'] = $pageId;
            $pages[$pageId]['automated_status'] = 'PASS';
            $pages[$pageId]['viewports'][$viewport] = [
                'screenshot' => $screenshot,
                'accessibility' => $axeFile,
                'accessibility_violations' => 0,
            ];
        }
        foreach (self::PAGES as $id) {
            if (count($pages[$id]['viewports'] ?? []) !== count(self::VIEWPORTS)) {
                throw new RuntimeException('Missing desktop/mobile evidence for '.$id);
            }
        }

        ksort($pages);
        $projection = [
            'schema' => 1,
            'suite' => 'golden-eight',
            'automated_status' => 'PASS',
            'human_acceptance' => false,
            'source_commit_sha' => $provenance['source_commit_sha'],
            'tested_checkout_sha' => $provenance['tested_checkout_sha'],
            'workflow_run_id' => (string) $provenance['workflow_run_id'],
            'workflow_run_attempt' => (string) $provenance['workflow_run_attempt'],
            'observed_at' => $provenance['observed_at'],
            'artifact_name' => 'golden-eight-evidence',
            'artifact_url' => 'https://github.com/Murakhovsky/TerraNova2026/actions/runs/'.$provenance['workflow_run_id'],
            'pages' => $pages,
        ];

        $directory = dirname($this->projectionPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create QA projection directory.');
        }
        $temporary = tempnam($directory, 'golden-qa-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage Golden Eight QA projection.');
        }
        try {
            $bytes = json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $bytes."\n") === false || !rename($temporary, $this->projectionPath)) {
                throw new RuntimeException('Cannot atomically publish Golden Eight QA projection.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $projection;
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        if (!is_file($this->projectionPath)) {
            return ['automated_status' => 'NOT_IMPORTED', 'pages' => [], 'human_acceptance' => false];
        }
        $projection = $this->decodeFile($this->projectionPath);
        // A projection is historical evidence, not a live claim about the deployed commit.
        $projection['human_acceptance'] = false;
        return $projection;
    }

    /** @return array<string,mixed>|null */
    public function forPage(string $pageId): ?array
    {
        $report = $this->report();
        $page = $report['pages'][$pageId] ?? null;
        return is_array($page) ? [
            ...$page,
            'source_commit_sha' => $report['source_commit_sha'] ?? null,
            'workflow_run_id' => $report['workflow_run_id'] ?? null,
            'observed_at' => $report['observed_at'] ?? null,
            'artifact_url' => $report['artifact_url'] ?? null,
            'human_acceptance' => false,
        ] : null;
    }

    /** @return array<string,mixed> */
    private function decodeFile(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Golden Eight QA evidence file missing: '.$file);
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Golden Eight QA evidence is not a JSON object: '.$file);
        }
        return $data;
    }
}
