<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class DataProvenance
{
    public function __construct(
        public PropertyIdentity $identity,
        public string $fieldPath,
        public string $sourceId,
        public mixed $observedValue,
        public DateTimeImmutable $importedAt,
        public float $confidence = 0.5,
        public ?DateTimeImmutable $observedAt = null,
        public ?int $externalReferenceId = null,
        public ?PropertyVerificationStatus $verificationStatus = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.\[\]-]{1,190}$/', $this->fieldPath)) {
            throw new InvalidArgumentException('DataProvenance field path must be a stable canonical path.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->sourceId)) {
            throw new InvalidArgumentException('DataProvenance source id must be stable.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException('DataProvenance confidence must be between 0 and 1.');
        }
        if ($this->externalReferenceId !== null && $this->externalReferenceId <= 0) {
            throw new InvalidArgumentException('DataProvenance external reference id must be positive.');
        }
        if ($this->observedAt !== null && $this->observedAt > $this->importedAt) {
            throw new InvalidArgumentException('DataProvenance observed time cannot be after import time.');
        }

        try {
            json_encode($this->observedValue, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('DataProvenance observed value must be JSON serializable.', previous: $exception);
        }
    }

    public function status(): PropertyVerificationStatus
    {
        return $this->verificationStatus ?? PropertyVerificationStatus::from(PropertyVerificationStatus::UNVERIFIED);
    }
}
