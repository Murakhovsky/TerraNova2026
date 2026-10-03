<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

use App\Engineering\Application\DTO\EngineeringRequest;

interface RepositoryDiscoveryInterface
{
    public function discover(EngineeringRequest $request): RepositoryContextMap;
}
