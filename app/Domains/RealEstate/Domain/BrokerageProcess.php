<?php
declare(strict_types=1);

namespace Domains\RealEstate\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class BrokerageProcess
{
    public const MATCHED = 'matched';
    public const OFFERED = 'offered';
    public const VIEWING = 'viewing';
    public const RESERVED = 'reserved';

    /** @var list<string> */
    private const STATUSES = [self::MATCHED, self::OFFERED, self::VIEWING, self::RESERVED];

    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public int $opportunityId,
        public string $propertyId,
        public ?string $inventoryId,
        public string $subject,
        public string $status = self::MATCHED,
    ) {
        if (trim($id) === '' || $opportunityId <= 0 || trim($propertyId) === '' || trim($subject) === '') {
            throw new InvalidArgumentException('Invalid RealEstate brokerage process.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid RealEstate brokerage status.');
        }
    }

    public function transitionTo(string $next): self
    {
        if ($next === $this->status) return $this;

        $allowed = match ($this->status) {
            self::MATCHED => [self::OFFERED, self::VIEWING, self::RESERVED],
            self::OFFERED => [self::VIEWING, self::RESERVED],
            self::VIEWING => [self::OFFERED, self::RESERVED],
            self::RESERVED => [],
            default => [],
        };
        if (!in_array($next, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'RealEstate transition %s -> %s is not allowed.',
                $this->status,
                $next,
            ));
        }

        return new self(
            $this->id,
            $this->organizationId,
            $this->opportunityId,
            $this->propertyId,
            $this->inventoryId,
            $this->subject,
            $next,
        );
    }
}
