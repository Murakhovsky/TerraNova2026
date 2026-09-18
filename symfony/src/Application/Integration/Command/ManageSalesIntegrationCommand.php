<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ManageSalesIntegrationCommand implements CommandInterface
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const TEST = 'test';
    public const SAVE_ROUTE = 'save_route';

    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $action,
        public string $correlationId,
        public string $idempotencyKey,
        public ?int $integrationId = null,
        public array $input = [],
    ) {
    }
}
