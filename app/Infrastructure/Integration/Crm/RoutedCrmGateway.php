<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmGatewayInterface;
use Domains\Sales\Application\Contract\OrganizationCrmResolverInterface;
use Domains\Sales\Application\DTO\CreateTaskCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Throwable;

final readonly class RoutedCrmGateway implements CrmGatewayInterface
{
    public function __construct(private OrganizationCrmResolverInterface $resolver, private CrmRegistry $registry)
    {
    }

    public function createTask(CreateTaskCommand $command): OperationResult
    {
        try {
            return $this->registry->get($this->resolver->providerFor($command->organizationId))->createTask($command);
        } catch (Throwable $exception) {
            return OperationResult::failure($exception->getMessage());
        }
    }
}
