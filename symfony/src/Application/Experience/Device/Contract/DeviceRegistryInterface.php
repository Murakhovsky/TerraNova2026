<?php

declare(strict_types=1);

namespace App\Application\Experience\Device\Contract;

use App\Application\Experience\Device\ClientVersion;
use App\Application\Experience\Device\DeviceCapabilities;
use App\Application\Experience\Device\UserDevice;
use App\Application\Experience\Device\UserDeviceRegistration;
use DateTimeImmutable;

interface DeviceRegistryInterface
{
    public function register(UserDeviceRegistration $registration): UserDevice;

    public function find(string $deviceId): ?UserDevice;

    /** @return list<UserDevice> */
    public function forUser(string $userId): array;

    public function touch(
        string $deviceId,
        ClientVersion $version,
        DeviceCapabilities $capabilities,
        DateTimeImmutable $seenAt,
    ): void;

    public function revoke(string $deviceId, DateTimeImmutable $revokedAt): void;
}
