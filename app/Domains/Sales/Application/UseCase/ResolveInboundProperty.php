<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;

final readonly class ResolveInboundProperty
{
    public function __construct(private ClientCaseCommandRepositoryInterface $commands)
    {
    }

    public function execute(mixed $value): ?int
    {
        $propertyId = is_numeric($value) ? (int) $value : 0;
        return $this->commands->property($propertyId, true) ? $propertyId : null;
    }
}
