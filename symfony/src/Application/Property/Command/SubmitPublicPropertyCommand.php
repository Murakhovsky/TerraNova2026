<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SubmitPublicPropertyCommand implements CommandInterface
{
    /** @param array<string,mixed> $input @param array<string,mixed> $files */
    public function __construct(
        public OrganizationId $organizationId,
        public array $input,
        public string $sourcePage,
        public array $files=[],
    ) {}
}
