<?php
declare(strict_types=1);

namespace Interfaces\Web\Visualization\Controller;

use Interfaces\Web\Controller\WebController;
use Kernel\Visualization\Graph\GraphProviderInterface;
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

            $mapper = $this->di->getShared('cosCytoscapeGraphMapper');
            if (!is_object($mapper) || !is_callable([$mapper, 'map'])) {
                throw new RuntimeException('Invalid Cytoscape graph mapper.');
            }

            /** @var array<string,mixed> $payload */
            $payload = $mapper->map($provider->provide());
            $this->view->architectureGraph = $payload;
        } catch (Throwable) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->architectureGraph = [
                'elements' => [],
                'summary' => [
                    'nodes' => 0,
                    'edges' => 0,
                    'groups' => 0,
                    'node_types' => [],
                    'relations' => [],
                    'domains' => [],
                ],
            ];
            $this->view->pageStatus = 'Architecture Graph тимчасово недоступний.';
        }

        $this->view->pick('visualization/architecture');
    }
}
