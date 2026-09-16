<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Throwable;

final readonly class FallbackArchitectureGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private GraphProviderInterface $primary,
        private GraphProviderInterface $fallback,
    ) {
    }

    public function provide(): Graph
    {
        try {
            return $this->primary->provide();
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[COS Visualization] Architecture Graph primary provider failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return $this->fallback->provide();
        }
    }
}
