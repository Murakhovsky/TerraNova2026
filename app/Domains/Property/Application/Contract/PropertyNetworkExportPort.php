<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Network\PropertyNetworkBatch;

interface PropertyNetworkExportPort
{
    public function batch(string $organizationId, array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch;
}
