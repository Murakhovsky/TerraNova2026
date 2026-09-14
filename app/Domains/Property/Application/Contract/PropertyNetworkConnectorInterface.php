<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Network\PropertyNetworkBatch;
use Domains\Property\Network\PropertyNetworkDeliveryResult;

interface PropertyNetworkConnectorInterface
{
    public function code(): string;

    public function pull(array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch;

    /** @param list<\Domains\Property\Network\PropertyNetworkRecord> $records */
    public function push(array $connector, array $records, ?string $cursor = null): PropertyNetworkDeliveryResult;
}
