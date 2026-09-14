<?php
declare(strict_types=1);

namespace Domains\Spatial\Application\Contract;

interface PropertyTourPublisherInterface
{
    public function publishTour(int $propertyId, string $url): void;
}
