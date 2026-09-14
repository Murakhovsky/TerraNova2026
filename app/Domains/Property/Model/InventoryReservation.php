<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InventoryReservation
{
    public function __construct(
        public string $organizationId,
        public string $reservationId,
        public string $inventoryId,
        public ?string $reservedForReference,
        public DateTimeImmutable $reservedAt,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $releasedAt = null,
        public ?string $reason = null,
    ) {
        if (trim($this->organizationId) === '') {
            throw new InvalidArgumentException('InventoryReservation organization id is required.');
        }
        foreach ([$this->reservationId, $this->inventoryId] as $identifier) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $identifier)) {
                throw new InvalidArgumentException('InventoryReservation identifiers must be stable.');
            }
        }
        if ($this->expiresAt !== null && $this->expiresAt < $this->reservedAt) {
            throw new InvalidArgumentException('Reservation cannot expire before it begins.');
        }
        if ($this->releasedAt !== null && $this->releasedAt < $this->reservedAt) {
            throw new InvalidArgumentException('Reservation cannot be released before it begins.');
        }
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->reservedAt
            && ($this->expiresAt === null || $at < $this->expiresAt)
            && ($this->releasedAt === null || $at < $this->releasedAt);
    }

    public function release(DateTimeImmutable $at): self
    {
        return new self(
            $this->organizationId,
            $this->reservationId,
            $this->inventoryId,
            $this->reservedForReference,
            $this->reservedAt,
            $this->expiresAt,
            $at,
            $this->reason,
        );
    }
}
