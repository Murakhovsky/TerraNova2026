<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class RealEstateMutationCommand implements CommandInterface
{
    public const MATCH='match';
    public const OFFER='offer';
    public const VIEWING='viewing';
    public const RESERVE='reserve';

    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $operation,
        public string|int $subjectId,
        public array $input,
    ){}
}
