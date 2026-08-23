<?php
declare(strict_types=1);

namespace Infrastructure\Crm;

use Domains\Sales\Crm\Contract\CrmGatewayInterface;
use Domains\Sales\Crm\Contract\OrganizationCrmResolverInterface;
use Domains\Sales\Crm\CreateTaskCommand;
use Domains\Sales\Crm\ExternalResult;
use Throwable;

final readonly class RoutedCrmGateway implements CrmGatewayInterface
{
    public function __construct(
        private OrganizationCrmResolverInterface $resolver,
        private CrmRegistry $registry,
    ) {
    }

    public function createTask(CreateTaskCommand $command): ExternalResult
    {
        try {
            $provider = $this->resolver->providerFor($command->organizationId);
            return $this->registry->get($provider)->createTask($command);
        } catch (Throwable $exception) {
            return ExternalResult::failure($exception->getMessage());
        }
    }
}
