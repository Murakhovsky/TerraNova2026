<?php
declare(strict_types=1);

namespace Interfaces\Web\Visualization\Controller;

use Interfaces\Web\Controller\WebController;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
use RuntimeException;
use Throwable;

final class ArchitectureExplorerController extends WebController
{
    public function indexAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $this->view->title = 'COS Architecture Explorer';
        $this->view->workspaceSection = 'cos';
        $this->view->pageAssetEntries = ['cos-architecture-explorer'];
        $this->view->pageStatus = null;

        try {
            $provider = $this->di->getShared('cosArchitectureGraphProvider');
            if (!$provider instanceof GraphProviderInterface) {
                throw new RuntimeException('Invalid architecture graph provider.');
            }

            $registry = $this->di->getShared('cosArchitectureProjectionRegistry');
            if (!$registry instanceof GraphProjectionRegistryInterface) {
                throw new RuntimeException('Invalid architecture projection registry.');
            }

            $mapper = $this->di->getShared('cosCytoscapeGraphMapper');
            if (!is_object($mapper) || !is_callable([$mapper, 'map'])) {
                throw new RuntimeException('Invalid Cytoscape graph mapper.');
            }

            $canonical = $provider->provide();
            /** @var array<string,mixed> $canonicalPayload */
            $canonicalPayload = $mapper->map($canonical);
            $views = [];
            foreach ($registry->names() as $name) {
                /** @var array<string,mixed> $projectionPayload */
                $projectionPayload = $mapper->map($registry->project($name, $canonical, new GraphView()));
                $views[$name] = $projectionPayload;
            }

            $names = $registry->names();
            $this->view->architectureGraph = [
                'views' => $views,
                'summary' => $canonicalPayload['summary'] ?? [],
                'default_view' => $registry->has('system') ? 'system' : ($names[0] ?? ''),
            ];
            $this->view->architectureViewDescriptions = $registry->descriptions();
        } catch (Throwable) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->architectureGraph = [
                'views' => [],
                'summary' => [
                    'nodes' => 0,
                    'edges' => 0,
                    'groups' => 0,
                    'node_types' => [],
                    'relations' => [],
                    'domains' => [],
                ],
                'default_view' => '',
            ];
            $this->view->architectureViewDescriptions = [];
            $this->view->pageStatus = 'Architecture Graph тимчасово недоступний.';
        }

        $this->view->pick('visualization/architecture');
    }
}
