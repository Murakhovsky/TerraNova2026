<?php
declare(strict_types=1);

namespace App\Command;

use Infrastructure\Visualization\Cytoscape\CytoscapeGraphMapper;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'cos:architecture:smoke', description: 'Validate the canonical COS Architecture Graph runtime.')]
final class ArchitectureGraphSmokeCommand extends Command
{
    public function __construct(
        private readonly GraphProviderInterface $provider,
        private readonly GraphProjectionRegistryInterface $projections,
        private readonly CytoscapeGraphMapper $mapper,
        private readonly GraphHealthAnalyzerInterface $health,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stage = 'build_canonical_graph';

        try {
            $canonical = $this->provider->provide();
            if (count($canonical->nodes()) === 0) {
                throw new \RuntimeException('Canonical Architecture Graph contains zero nodes.');
            }

            $stage = 'analyze_canonical_graph';
            $health = $this->health->analyze($canonical);
            if (!isset($health['status'], $health['total'], $health['issues']) || !is_array($health['issues'])) {
                throw new \RuntimeException('Architecture Graph health analyzer returned an invalid payload.');
            }

            $names = $this->projections->names();
            $viewName = $this->projections->has('system') ? 'system' : ($names[0] ?? '');
            if ($viewName === '') {
                throw new \RuntimeException('Architecture projection registry contains no projections.');
            }

            $stage = 'project_' . $viewName;
            $description = $this->projections->descriptions()[$viewName] ?? ['layout' => 'auto'];
            $projected = $this->projections->project(
                $viewName,
                $canonical,
                new GraphView(layout: (string) ($description['layout'] ?? 'auto')),
            );
            if (count($projected->nodes()) === 0) {
                throw new \RuntimeException(sprintf('Architecture projection %s contains zero nodes.', $viewName));
            }

            $stage = 'map_' . $viewName;
            $payload = $this->mapper->map($projected);
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            if ((int) ($summary['nodes'] ?? 0) <= 0) {
                throw new \RuntimeException(sprintf('Cytoscape payload for %s contains zero nodes.', $viewName));
            }

            $output->writeln(sprintf(
                'Architecture Graph runtime smoke passed: canonical_nodes=%d health=%s issues=%d projection=%s projection_nodes=%d projection_edges=%d',
                count($canonical->nodes()),
                (string) $health['status'],
                (int) $health['total'],
                $viewName,
                (int) ($summary['nodes'] ?? count($projected->nodes())),
                (int) ($summary['edges'] ?? count($projected->edges())),
            ));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $detail = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'No exception message.';
            $output->writeln(sprintf(
                '<error>Architecture Graph runtime smoke failed [%s] %s: %s</error>',
                $stage,
                $exception::class,
                $detail,
            ));
            return Command::FAILURE;
        }
    }
}
