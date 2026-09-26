<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use InvalidArgumentException;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;
use Kernel\Visualization\Graph\GraphMapperInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;

final readonly class ArchitectureGraphQueryService
{
    public function __construct(
        private GraphProviderInterface $provider,
        private GraphProjectionRegistryInterface $registry,
        private GraphHealthAnalyzerInterface $health,
        private GraphMapperInterface $mapper,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $canonical = $this->provider->provide();
        $descriptions = $this->registry->descriptions();
        $names = $this->registry->names();
        $defaultView = $this->registry->has('system') ? 'system' : ($names[0] ?? '');
        $canonicalPayload = $this->mapper->map($canonical);

        return [
            'summary' => is_array($canonicalPayload['summary'] ?? null) ? $canonicalPayload['summary'] : [],
            'views' => $descriptions,
            'default_view' => $defaultView,
            'health' => $this->health->analyze($canonical),
        ];
    }

    /** @return array<string,mixed> */
    public function projection(string $name, ?string $focus = null, ?int $depth = null): array
    {
        if (!$this->registry->has($name)) {
            throw new InvalidArgumentException('Unknown architecture projection.');
        }

        $canonical = $this->provider->provide();
        if ($focus !== null && $focus !== '' && !$canonical->hasNode($focus)) {
            throw new InvalidArgumentException('Architecture focus node was not found.');
        }

        $description = $this->registry->descriptions()[$name] ?? [
            'label' => $name,
            'layout' => 'auto',
            'default_depth' => null,
        ];

        return $this->projectionPayload(
            $canonical,
            $name,
            new GraphView(
                focus: $focus !== '' ? $focus : null,
                depth: $depth,
                layout: (string) ($description['layout'] ?? 'auto'),
            ),
            $description,
        );
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->health->analyze($this->provider->provide());
    }

    /** @param array<string,mixed> $description @return array<string,mixed> */
    private function projectionPayload(Graph $canonical, string $name, GraphView $view, array $description): array
    {
        $payload = $this->mapper->map($this->registry->project($name, $canonical, $view));
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
