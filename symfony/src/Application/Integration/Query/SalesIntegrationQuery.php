<?php
declare(strict_types=1);

namespace App\Application\Integration\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SalesIntegrationQuery implements QueryInterface
{
    public const CATALOG = 'catalog';
    public const LIST = 'list';
    public const ROUTING_OPTIONS = 'routing_options';
    public const VIEW = 'view';
    public const REVISIONS = 'revisions';

    public function __construct(
        public OrganizationId $organizationId,
        public string $operation,
        public ?int $integrationId = null,
        public int $limit = 100,
    ) {
    }
}
