<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

final readonly class ClientVersionPolicy
{
    public function __construct(
        public ClientVersion $minimumSupportedVersion,
        public ClientVersion $recommendedVersion,
        public DeviceCapabilities $requiredCapabilities = new DeviceCapabilities(),
    ) {
    }

    public function evaluate(ClientVersion $clientVersion, DeviceCapabilities $capabilities): ClientCompatibility
    {
        $missing = [];

        foreach ($this->requiredCapabilities->all() as $required) {
            if (!$capabilities->supports($required)) {
                $missing[] = $required;
            }
        }

        $status = ClientCompatibilityStatus::Supported;

        if (!$clientVersion->isAtLeast($this->minimumSupportedVersion) || $missing !== []) {
            $status = ClientCompatibilityStatus::UpgradeRequired;
        } elseif (!$clientVersion->isAtLeast($this->recommendedVersion)) {
            $status = ClientCompatibilityStatus::UpgradeRecommended;
        }

        return new ClientCompatibility(
            status: $status,
            clientVersion: $clientVersion,
            minimumSupportedVersion: $this->minimumSupportedVersion,
            recommendedVersion: $this->recommendedVersion,
            missingCapabilities: $missing,
        );
    }
}
