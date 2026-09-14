<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Network\PropertyNetworkRecord;

interface PropertyNetworkIntakePort
{
    public function import(string $organizationId, array $connector, PropertyNetworkRecord $record): int;
}
