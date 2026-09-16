<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Visualization\Architecture\FallbackArchitectureGraphProvider;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\Node;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$primaryFailure = new class implements GraphProviderInterface {
    public function provide(): Graph
    {
        throw new RuntimeException('runtime topology unavailable');
    }
};

$staticGraph = new Graph([
    new Node('kernel:cos', 'kernel', 'COS Kernel'),
    new Node('domain:sales', 'domain', 'Sales'),
]);

$fallback = new class($staticGraph) implements GraphProviderInterface {
    public int $calls = 0;

    public function __construct(private Graph $graph)
    {
    }

    public function provide(): Graph
    {
        $this->calls++;
        return $this->graph;
    }
};

$result = (new FallbackArchitectureGraphProvider($primaryFailure, $fallback))->provide();
$assert($result->hasNode('kernel:cos'), 'Fallback graph must remain available when runtime topology fails.');
$assert($result->hasNode('domain:sales'), 'Fallback graph must preserve structural module nodes.');
$assert($fallback->calls === 1, 'Fallback provider must be called exactly once after primary failure.');

$primaryGraph = new Graph([new Node('domain:property', 'domain', 'Property')]);
$primary = new class($primaryGraph) implements GraphProviderInterface {
    public function __construct(private Graph $graph)
    {
    }

    public function provide(): Graph
    {
        return $this->graph;
    }
};

$neverFallback = new class implements GraphProviderInterface {
    public function provide(): Graph
    {
        throw new RuntimeException('Fallback must not run when primary graph is healthy.');
    }
};

$result = (new FallbackArchitectureGraphProvider($primary, $neverFallback))->provide();
$assert($result->hasNode('domain:property'), 'Healthy primary graph must win over fallback.');

echo "Visualization fallback graph provider passed.\n";
