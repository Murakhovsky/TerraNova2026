<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Spatial;

use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Spatial\Application\Contract\PropertyTourPublisherInterface;

final readonly class CanonicalPropertyTourPublisher implements PropertyTourPublisherInterface
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private string $organizationId,
    ) {}

    public function publishTour(int $propertyId, string $url): void
    {
        $this->runtime->patchBundleByLegacyId(
            $this->organizationId,
            $propertyId,
            ['tour_url' => trim($url), 'has_3d_tour' => 1],
            null,
            bin2hex(random_bytes(12)),
        );
    }
}
