<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Command;

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreateProductCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ProductManagementService $products) {}

    /** @return array<string,mixed> */
    public function __invoke(CreateProductCommand $command): array
    {
        return $this->products->createProduct(
            $command->organization->value(),$command->data,
            $command->idempotencyKey,$command->actorId,$command->correlationId
        );
    }
}
