<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PropertyPartyRelation
{
    private const STATUSES = ['active', 'ended', 'disputed'];

    public function __construct(
        public PropertyIdentity $identity,
        public string $partyReference,
        public PropertyPartyRelationType $type,
        public string $status = 'active',
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validTo = null,
        public ?string $sourceId = null,
        public float $confidence = 0.5,
    ) {
        if (trim($this->partyReference) === '' || mb_strlen($this->partyReference) > 191) {
            throw new InvalidArgumentException('PropertyPartyRelation party reference is required.');
        }
        if (!in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported PropertyPartyRelation status: ' . $this->status);
        }
        if ($this->validFrom !== null && $this->validTo !== null && $this->validTo <= $this->validFrom) {
            throw new InvalidArgumentException('PropertyPartyRelation validity interval is invalid.');
        }
        if ($this->sourceId !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->sourceId)) {
            throw new InvalidArgumentException('PropertyPartyRelation source id must be stable when present.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException('PropertyPartyRelation confidence must be between 0 and 1.');
        }
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }
        return $this->validTo === null || $at < $this->validTo;
    }
}
