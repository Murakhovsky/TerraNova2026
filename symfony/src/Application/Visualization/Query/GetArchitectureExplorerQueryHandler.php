<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphMapperInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;

final readonly class GetArchitectureExplorerQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private GraphProviderInterface $provider,
        private GraphProjectionRegistryInterface $registry,
        private GraphHealthAnalyzerInterface $health,
        private GraphMapperInterface $mapper,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetArchitectureExplorerQuery $query): array
    {
        $canonical = $this->provider->provide();
        $health = $this->health->analyze($canonical);
        $descriptions = $this->registry->descriptions();
        $canonicalPayload = $this->mapper->map($canonical);
        $views = [];

        foreach ($this->registry->names() as $name) {
            $description = $descriptions[$name] ?? [
                'label' => $name,
                'layout' => 'auto',
                'default_depth' => null,
            ];
            $views[$name] = $this->projectionPayload(
                $canonical,
                $name,
                new GraphView(layout: (string) ($description['layout'] ?? 'auto')),
                $description,
            );
        }

        $names = $this->registry->names();
        $defaultView = $this->registry->has('system') ? 'system' : ($names[0] ?? '');

        return [
            'graph' => [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $defaultView,
            ],
            'descriptions' => $descriptions,
            'health' => $health,
        ];
    }

    /** @param array<string,mixed> $description @return array<string,mixed> */
    private function projectionPayload(
        Graph $canonical,
        string $name,
        GraphView $view,
        array $description,
    ): array {
        $payload = $this->mapper->map(
            $this->registry->project($name, $canonical, $view),
        );
        $payload['view'] = [
            'name' => $name,
            'label' => (string) ($description['label'] ?? $name),
            'layout' => (string) ($description['layout'] ?? $view->layout),
            'focus' => $view->focus,
            'depth' => $view->depth,
            'default_depth' => $description['default_depth'] ?? null,
        ];

        return $payload;
    }
}
