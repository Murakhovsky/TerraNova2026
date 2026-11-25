<?php
declare(strict_types=1);
namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ManageSalesOfferCommand implements CommandInterface
{
    public function __construct(public OrganizationId $organizationId,public int $actorId,public string $operation,
        public array $input,public string $idempotencyKey,public string $correlationId){}
}
