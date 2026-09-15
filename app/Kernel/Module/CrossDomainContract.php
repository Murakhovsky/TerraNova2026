<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class CrossDomainContract
{
    public const ROLE_REQUIRES = 'requires';
    public const ROLE_PROVIDES = 'provides';

    public function __construct(
        public string $contract,
        public string $role,
        public string $counterpart,
        public string $kind = 'synchronous_port',
        public string $purpose = '',
    ) {
        if (!preg_match('/^Domains\\\\[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)+$/', $this->contract)) {
            throw new InvalidArgumentException(sprintf('Invalid cross-domain contract class: %s.', $this->contract));
        }
        if (!in_array($this->role, [self::ROLE_REQUIRES, self::ROLE_PROVIDES], true)) {
            throw new InvalidArgumentException(sprintf('Invalid cross-domain contract role: %s.', $this->role));
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->counterpart)) {
            throw new InvalidArgumentException(sprintf('Invalid cross-domain counterpart: %s.', $this->counterpart));
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->kind)) {
            throw new InvalidArgumentException(sprintf('Invalid cross-domain contract kind: %s.', $this->kind));
        }
    }

    /** @param array<string,mixed> $definition */
    public static function fromArray(array $definition): self
    {
        return new self(
            trim((string) ($definition['contract'] ?? '')),
            trim((string) ($definition['role'] ?? '')),
            trim((string) ($definition['counterpart'] ?? '')),
            trim((string) ($definition['kind'] ?? 'synchronous_port')),
            trim((string) ($definition['purpose'] ?? '')),
        );
    }

    public function consumerDomain(string $declaringDomain): string
    {
        $this->assertDeclaringDomain($declaringDomain);
        return $this->role === self::ROLE_REQUIRES ? $declaringDomain : $this->counterpart;
    }

    public function providerDomain(string $declaringDomain): string
    {
        $this->assertDeclaringDomain($declaringDomain);
        return $this->role === self::ROLE_PROVIDES ? $declaringDomain : $this->counterpart;
    }

    private function assertDeclaringDomain(string $declaringDomain): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $declaringDomain)) {
            throw new InvalidArgumentException(sprintf('Invalid declaring domain: %s.', $declaringDomain));
        }
        if ($declaringDomain === $this->counterpart) {
            throw new InvalidArgumentException(sprintf(
                'Cross-domain contract %s cannot point back to declaring domain %s.',
                $this->contract,
                $declaringDomain,
            ));
        }
    }
}
