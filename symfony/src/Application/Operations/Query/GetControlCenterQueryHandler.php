<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;

final readonly class GetControlCenterQueryHandler implements QueryHandlerInterface
{
    public function __construct(private OperationsReadModelInterface $operations)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetControlCenterQuery $query): array
    {
        return $this->operations->overview(
            $query->organizationId->value(),
            max(1, min(100, $query->limit)),
        );
    }
}
