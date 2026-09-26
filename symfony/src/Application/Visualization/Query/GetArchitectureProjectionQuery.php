<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetArchitectureProjectionQuery implements QueryInterface
{
    public function __construct(
        public string $view,
        public ?string $focus = null,
        public ?int $depth = null,
    ) {
    }
}
