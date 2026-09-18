<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Property\Contract\PropertyReferencePort;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Infrastructure\Property\SalesPropertyReference;
use InvalidArgumentException;
use PDO;

final readonly class MysqlClientCaseReadModelFactory implements ClientCaseReadModelFactoryInterface
{
    public function __construct(
        private PDO $connection,
        private PropertyReferencePort $properties,
    ) {
    }

    public function forOrganization(string $organizationId): ClientCaseReadModelInterface
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('organizationId is required.');
        }

        return new MysqlClientCaseReadModel(
            $this->connection,
            $organizationId,
            new SalesPropertyReference($this->properties, $organizationId),
        );
    }
}
