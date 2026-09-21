<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

use DateTimeImmutable;

final readonly class UserDeviceRegistration
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $platform,
        public ClientVersion $appVersion,
        public string $deviceName,
        public DeviceCapabilities $capabilities,
        public DateTimeImmutable $registeredAt,
        public ?string $pushToken = null,
    ) {
    }

    public function toDevice(): UserDevice
    {
        return new UserDevice(
            id: $this->id,
            userId: $this->userId,
            platform: $this->platform,
            appVersion: $this->appVersion,
            deviceName: $this->deviceName,
            capabilities: $this->capabilities,
            lastSeenAt: $this->registeredAt,
            pushToken: $this->pushToken,
        );
    }
}
