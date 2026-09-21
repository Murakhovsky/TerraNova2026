<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class UserDevice
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $platform,
        public ClientVersion $appVersion,
        public string $deviceName,
        public DeviceCapabilities $capabilities,
        public DateTimeImmutable $lastSeenAt,
        public ?string $pushToken = null,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
        if ($this->id === '' || trim($this->id) !== $this->id) {
            throw new InvalidArgumentException('UserDevice id must be a non-empty canonical identifier.');
        }

        if ($this->userId === '' || trim($this->userId) !== $this->userId) {
            throw new InvalidArgumentException('UserDevice user id must be a non-empty canonical identifier.');
        }

        if (!in_array($this->platform, ['ios', 'android', 'pwa'], true)) {
            throw new InvalidArgumentException('UserDevice platform must be ios, android or pwa.');
        }

        if ($this->deviceName === '' || trim($this->deviceName) !== $this->deviceName) {
            throw new InvalidArgumentException('UserDevice device name must be non-empty.');
        }
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
