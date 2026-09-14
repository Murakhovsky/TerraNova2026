<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExternalReference
{
    public function __construct(
        public PropertyIdentity $identity,
        public string $sourceId,
        public string $sourceSystem,
        public string $externalId,
        public DateTimeImmutable $importedAt,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->sourceId)) {
            throw new InvalidArgumentException('ExternalReference source id must be stable.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,79}$/', $this->sourceSystem)) {
            throw new InvalidArgumentException('ExternalReference source system must be stable.');
        }
        if (trim($this->externalId) === '' || mb_strlen($this->externalId) > 191) {
            throw new InvalidArgumentException('ExternalReference external id is required.');
        }
    }

    public function lookupKey(): string
    {
        return $this->identity->organizationId . ':' . $this->sourceSystem . ':' . $this->externalId;
    }
}
