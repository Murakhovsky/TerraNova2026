<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class PropertyMutationCommand implements CommandInterface
{
    public const CREATE='create';
    public const UPDATE='update';
    public const CREATE_INVENTORY='create_inventory';
    public const CHANGE_INVENTORY_STATUS='change_inventory_status';
    public const RESERVE_INVENTORY='reserve_inventory';

    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $operation,
        public ?string $reference,
        public array $input,
        public ?string $idempotencyKey=null,
    ) {}
}
