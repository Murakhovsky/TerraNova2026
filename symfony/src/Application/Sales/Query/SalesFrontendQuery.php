<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SalesFrontendQuery implements QueryInterface
{
    public const SEARCH = 'search';
    public const INTELLIGENCE = 'intelligence';

    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public string $operation,
        public array $input = [],
    ) {
    }
}
