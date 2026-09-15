<?php
declare(strict_types=1);

namespace Interfaces\Web\Visualization\Controller;

use Interfaces\Web\Controller\WebController;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
use RuntimeException;
use Throwable;

final class ArchitectureExplorerController extends WebController
{
    public function indexAction(): void
    {
        if ($this->requireManager() === null) {
            return;
        }

        $this->view->title = 'COS Architecture Explorer';
        $this->view->workspaceSection = 'cos';
        $this->view->pageAssetEntries = ['cos-architecture-explorer'];
        $this->view->pageStatus = null;

        try {
            $provider = $this->graphProvider();
            $registry = $this->projectionRegistry();
            $mapper = $this->graphMapper();
            $canonical = $provider->provide();
            $descriptions = $registry->descriptions();

            /** @var array<string,mixed> $canonicalPayload */
            $canonicalPayload = $mapper->map($canonical);
            $views = [];
            foreach ($registry->names() as $name) {
                $description = $descriptions[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
                $view = new GraphView(layout: (string) ($description['layout'] ?? 'auto'));
                $views[$name] = $this->projectionPayload($mapper, $registry, $canonical, $name, $view, $description);
            }

            $names = $registry->names();
            $defaultView = $registry->has('system') ? 'system' : ($names[0] ?? '');
            $this->view->architectureGraph = [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $defaultView,
            ];
            $this->view->architectureViewDescriptions = $descriptions;
        } catch (Throwable) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->architectureGraph = [
                'views' => [],
                'summary' => $this->emptySummary(),
                'default_view' => '',
            ];
            $this->view->architectureViewDescriptions = [];
            $this->view->pageStatus = 'Architecture Graph тимчасово недоступний.';
        }

        $this->view->pick('visualization/architecture');
    }

    public function graphAction(): void
    {
        if ($this->requireManager() === null) {
            return;
        }

        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');

        try {
            $provider = $this->graphProvider();
            $registry = $this->projectionRegistry();
            $mapper = $this->graphMapper();
            $name = trim((string) $this->request->getQuery('view', 'string', 'domain'));

            if (!$registry->has($name)) {
                $this->json(404, ['ok' => false, 'error' => 'Unknown architecture projection.']);
                return;
            }

            $canonical = $provider->provide();
            $focus = trim((string) $this->request->getQuery('focus', 'string', ''));
            $depthValue = trim((string) $this->request->getQuery('depth', 'string', ''));
            $depth = null;

            if ($depthValue === 'all') {
                $focus = '';
            } elseif ($depthValue !== '') {
                if (!ctype_digit($depthValue)) {
                    $this->json(400, ['ok' => false, 'error' => 'Depth must be a non-negative integer or all.']);
                    return;
                }
                $depth = (int) $depthValue;
                if ($depth > 6) {
                    $this->json(400, ['ok' => false, 'error' => 'Depth cannot exceed 6 hops.']);
                    return;
                }
            }

            if ($focus !== '' && !$canonical->hasNode($focus)) {
                $this->json(404, ['ok' => false, 'error' => 'Architecture focus node was not found.']);
                return;
            }

            $description = $registry->descriptions()[$name] ?? ['label' => $name, 'layout' => 'auto', 'default_depth' => null];
            $view = new GraphView(
                focus: $focus !== '' ? $focus : null,
                depth: $depth,
                layout: (string) ($description['layout'] ?? 'auto'),
            );

            $payload = $this->projectionPayload($mapper, $registry, $canonical, $name, $view, $description);
            $this->json(200, ['ok' => true, 'graph' => $payload]);
        } catch (Throwable) {
            $this->json(503, ['ok' => false, 'error' => 'Architecture Graph is temporarily unavailable.']);
        }
    }

    private function graphProvider(): GraphProviderInterface
    {
        $provider = $this->di->getShared('cosArchitectureGraphProvider');
        if (!$provider instanceof GraphProviderInterface) {
            throw new RuntimeException('Invalid architecture graph provider.');
        }
        return $provider;
    }

    private function projectionRegistry(): GraphProjectionRegistryInterface
    {
        $registry = $this->di->getShared('cosArchitectureProjectionRegistry');
        if (!$registry instanceof GraphProjectionRegistryInterface) {
            throw new RuntimeException('Invalid architecture projection registry.');
        }
        return $registry;
    }

    private function graphMapper(): object
    {
        $mapper = $this->di->getShared('cosCytoscapeGraphMapper');
        if (!is_object($mapper) || !is_callable([$mapper, 'map'])) {
            throw new RuntimeException('Invalid Cytoscape graph mapper.');
        }
        return $mapper;
    }

    /**
     * @param array<string,mixed> $description
     * @return array<string,mixed>
     */
    private function projectionPayload(
        object $mapper,
        GraphProjectionRegistryInterface $registry,
        Graph $canonical,
        string $name,
        GraphView $view,
        array $description,
    ): array {
        /** @var array<string,mixed> $payload */
        $payload = $mapper->map($registry->project($name, $canonical, $view));
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

    /** @param array<string,mixed> $payload */
    private function json(int $status, array $payload): void
    {
        $this->response->setStatusCode($status);
        $this->response->setJsonContent($payload);
    }

    /** @return array{nodes:int,edges:int,groups:int,node_types:array<string,int>,relations:array<string,int>,domains:array<int,mixed>} */
    private function emptySummary(): array
    {
        return [
            'nodes' => 0,
            'edges' => 0,
            'groups' => 0,
            'node_types' => [],
            'relations' => [],
            'domains' => [],
        ];
    }
}
