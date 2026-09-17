#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\GraphView;
use Phalcon\Di\FactoryDefault\Cli as FactoryDefault;
use RuntimeException;
use Throwable;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/config/environment.php';
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

$di = new FactoryDefault();
require APP_PATH . '/config/services.php';

$stage = 'resolve_provider';

try {
    $provider = $di->getShared('cosArchitectureGraphProvider');
    if (!$provider instanceof GraphProviderInterface) {
        throw new RuntimeException('cosArchitectureGraphProvider does not implement GraphProviderInterface.');
    }

    $stage = 'resolve_projection_registry';
    $registry = $di->getShared('cosArchitectureProjectionRegistry');
    if (!$registry instanceof GraphProjectionRegistryInterface) {
        throw new RuntimeException('cosArchitectureProjectionRegistry does not implement GraphProjectionRegistryInterface.');
    }

    $stage = 'resolve_mapper';
    $mapper = $di->getShared('cosCytoscapeGraphMapper');
    if (!is_object($mapper) || !is_callable([$mapper, 'map'])) {
        throw new RuntimeException('cosCytoscapeGraphMapper is not callable.');
    }

    $stage = 'build_canonical_graph';
    $canonical = $provider->provide();
    if (count($canonical->nodes()) === 0) {
        throw new RuntimeException('Canonical Architecture Graph contains zero nodes.');
    }

    $names = $registry->names();
    $viewName = $registry->has('system') ? 'system' : ($names[0] ?? '');
    if ($viewName === '') {
        throw new RuntimeException('Architecture projection registry contains no projections.');
    }

    $stage = 'project_' . $viewName;
    $description = $registry->descriptions()[$viewName] ?? ['layout' => 'auto'];
    $projected = $registry->project(
        $viewName,
        $canonical,
        new GraphView(layout: (string) ($description['layout'] ?? 'auto')),
    );
    if (count($projected->nodes()) === 0) {
        throw new RuntimeException(sprintf('Architecture projection %s contains zero nodes.', $viewName));
    }

    $stage = 'map_' . $viewName;
    /** @var array<string,mixed> $payload */
    $payload = $mapper->map($projected);
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    if ((int) ($summary['nodes'] ?? 0) <= 0) {
        throw new RuntimeException(sprintf('Cytoscape payload for %s contains zero nodes.', $viewName));
    }

    echo sprintf(
        "Architecture Graph runtime smoke passed: canonical_nodes=%d projection=%s projection_nodes=%d projection_edges=%d\n",
        count($canonical->nodes()),
        $viewName,
        (int) ($summary['nodes'] ?? count($projected->nodes())),
        (int) ($summary['edges'] ?? count($projected->edges())),
    );
    exit(0);
} catch (Throwable $exception) {
    $detail = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'No exception message.';
    fwrite(STDERR, sprintf(
        "Architecture Graph runtime smoke failed [%s] %s: %s\n",
        $stage,
        $exception::class,
        $detail,
    ));
    exit(1);
}
