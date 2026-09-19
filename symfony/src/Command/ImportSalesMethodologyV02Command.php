<?php
declare(strict_types=1);

namespace App\Command;

use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'cos:diagnostic:sales-methodology:import-v02',
    description: 'Import, validate and regression-test Sales Diagnostic Methodology v0.2.0.',
)]
final class ImportSalesMethodologyV02Command extends Command
{
    public function __construct(
        private readonly PdoConnection $database,
        private readonly MethodologyStudioService $studio,
        private readonly string $rootDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'Organization id.')
            ->addArgument('user', InputArgument::OPTIONAL, 'Audit actor id.', 'system-methodology-import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) $input->getArgument('organization'));
        $userId = trim((string) $input->getArgument('user')) ?: 'system-methodology-import';
        if ($organizationId === '') {
            $output->writeln('<error>organization is required.</error>');
            return Command::INVALID;
        }

        $source = $this->json('/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json');
        $overrides = $this->json('/resources/diagnostic/sales/0.2.0/studio-overrides.json');
        $scenarioTemplates = $this->json('/resources/diagnostic/sales/0.2.0/regression-scenarios.json');
        $map = [
            'sections' => 'AREA',
            'criteria' => 'CRITERION',
            'facts' => 'FACT',
            'metrics' => 'METRIC',
            'questions' => 'QUESTION',
            'evidence_requirements' => 'EVIDENCE_REQUIREMENT',
            'rules' => 'RULE',
            'dependencies' => 'DEPENDENCY',
            'recommendations' => 'RECOMMENDATION',
            'benchmarks' => 'BENCHMARK',
            'scoring' => 'SCORING',
        ];

        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $this->studio->create($organizationId, [
                'slug' => 'sales',
                'name' => $overrides['pack']['name'],
                'domain' => $overrides['pack']['domain'],
                'description' => $overrides['pack']['description'],
                'methodology_version' => '0.2.0',
            ], $userId);

            foreach ($map as $field => $type) {
                foreach ($source[$field] ?? [] as $position => $entity) {
                    if ($type === 'METRIC') {
                        unset($entity['formula']);
                        $entity = array_replace($entity, $overrides['metrics'][$entity['id']] ?? []);
                    }
                    if ($type === 'RECOMMENDATION') {
                        $criterion = (string) (($entity['criteria'][0] ?? ''));
                        $missingRule = $criterion === '' ? '' : 'rule-' . $criterion . '-missing';
                        if ($missingRule !== '' && in_array($missingRule, array_column($source['rules'] ?? [], 'id'), true)) {
                            $entity['trigger_rules'] = array_values(array_unique(array_merge(
                                $entity['trigger_rules'] ?? [],
                                [$missingRule],
                            )));
                        }
                    }

                    $this->studio->saveEntity(
                        $organizationId,
                        'sales',
                        '0.2.0',
                        $type,
                        $entity + ['order' => $position],
                        $userId,
                    );
                }
            }

            $healthyFacts = array_fill_keys(array_column($source['facts'], 'id'), true);
            $healthyMetrics = [];
            foreach ($source['metrics'] as $metric) {
                $healthyMetrics[$metric['id']] = ($metric['direction'] ?? '') === 'lower_is_better' ? 10 : 90;
            }

            $this->studio->saveScenario($organizationId, 'sales', '0.2.0', [
                'id' => 'healthy-baseline',
                'name' => 'Healthy sales operating system',
                'input' => ['facts' => $healthyFacts, 'metrics' => $healthyMetrics],
                'expected' => ['findings' => [], 'recommendations' => [], 'score_min' => 75],
            ], $userId);

            foreach ($scenarioTemplates['scenarios'] ?? [] as $scenario) {
                $facts = array_replace(
                    $healthyFacts,
                    is_array($scenario['fact_overrides'] ?? null) ? $scenario['fact_overrides'] : [],
                );
                foreach (is_array($scenario['unset_facts'] ?? null) ? $scenario['unset_facts'] : [] as $factId) {
                    unset($facts[(string) $factId]);
                }

                $metrics = array_replace(
                    $healthyMetrics,
                    is_array($scenario['metric_overrides'] ?? null) ? $scenario['metric_overrides'] : [],
                );
                $this->studio->saveScenario($organizationId, 'sales', '0.2.0', [
                    'id' => (string) $scenario['id'],
                    'name' => (string) $scenario['name'],
                    'input' => ['facts' => $facts, 'metrics' => $metrics],
                    'expected' => is_array($scenario['expected'] ?? null) ? $scenario['expected'] : [],
                ], $userId);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $validation = $this->studio->validate($organizationId, 'sales', '0.2.0');
        if (!$validation['valid']) {
            throw new \RuntimeException(
                'Imported Sales v0.2.0 is invalid: ' . json_encode($validation['errors'], JSON_THROW_ON_ERROR),
            );
        }

        $regression = $this->studio->runRegression($organizationId, 'sales', '0.2.0');
        if ($regression['passed'] < 1 || $regression['failed'] > 0 || $regression['changed'] > 0) {
            throw new \RuntimeException(
                'Imported Sales v0.2.0 failed regression: ' . json_encode($regression, JSON_THROW_ON_ERROR),
            );
        }

        $output->writeln(sprintf(
            'Imported and validated Sales Diagnostic Pack v0.2.0; %d scenario(s) PASSED.',
            (int) $regression['passed'],
        ));
        return Command::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function json(string $relativePath): array
    {
        $path = rtrim($this->rootDir, '/\\') . $relativePath;
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected JSON object/array in ' . $relativePath);
        }
        return $decoded;
    }
}
