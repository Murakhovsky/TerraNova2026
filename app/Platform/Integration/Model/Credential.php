<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Credential
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $connectionId,
        public string $kind,
        public string $secretReference,
        public array $scopes = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (trim($this->id) === '' || trim($this->connectionId) === '' || trim($this->kind) === '') {
            throw new InvalidArgumentException('Credential requires id, connection and kind.');
        }
        if (trim($this->secretReference) === '') {
            throw new InvalidArgumentException('Credential stores a secret reference, never raw secret material.');
        }
        if (count(array_unique($this->scopes)) !== count($this->scopes)) {
            throw new InvalidArgumentException('Credential scopes must be unique.');
        }
    }

    public function expired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
