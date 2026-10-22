<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use RuntimeException;
use App\Web\Experience\Governance\GoldenQaEvidenceRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PageContractCompiler
{
    public function __construct(
        private CompiledPageContractRegistry $registry,
        private GoldenQaEvidenceRegistry $qaEvidence,
        #[Autowire('%kernel.project_dir%/var/experience/manifest.json')]
        private string $manifestPath,
    ) {
    }

    public function compile(): string
    {
        $directory = dirname($this->manifestPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create Experience manifest directory: ' . $directory);
        }

        $evidence = $this->qaEvidence->report();
        $payload = [
            'schema' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'qa_evidence_provenance' => [
                'source_commit_sha' => $evidence['source_commit_sha'] ?? null,
                'workflow_run_id' => $evidence['workflow_run_id'] ?? null,
                'observed_at' => $evidence['observed_at'] ?? null,
            ],
            'pages' => array_values(array_map(
                static fn (PageContract $contract): array => [
                    ...$contract->toManifest(),
                    'qa_evidence' => $evidence['pages'][$contract->id->value] ?? null,
                ],
                $this->registry->all(),
            )),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->manifestPath, $json . PHP_EOL);

        return $this->manifestPath;
    }
}
