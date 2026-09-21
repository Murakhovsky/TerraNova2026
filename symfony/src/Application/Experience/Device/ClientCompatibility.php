<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

final readonly class ClientCompatibility
{
    /**
     * @param list<DeviceCapability> $missingCapabilities
     */
    public function __construct(
        public ClientCompatibilityStatus $status,
        public ClientVersion $clientVersion,
        public ClientVersion $minimumSupportedVersion,
        public ClientVersion $recommendedVersion,
        public array $missingCapabilities = [],
    ) {
    }
}
