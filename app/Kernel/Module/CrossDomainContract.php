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
        public string $version = '1.0.0',
        public string $supportedVersionRange = '*',
        public string $inputSchema = '',
        public string $outputSchema = '',
        public string $failureSemantics = '',
        public string $idempotencySemantics = '',
        public string $tenantSemantics = 'tenant_scoped',
        public array $authorizationRequirements = [],
        public string $deprecationPolicy = '',
        public array $compatibilityTests = [],
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
        VersionConstraint::assertVersion($this->version, 'cross-domain contract version');
        VersionConstraint::assertConstraint($this->supportedVersionRange);
        if (!in_array($this->tenantSemantics, ['tenant_scoped', 'system_only', 'explicit_mixed'], true)) {
            throw new InvalidArgumentException('Invalid contract tenant semantics: ' . $this->contract);
        }
        foreach ([$this->authorizationRequirements, $this->compatibilityTests] as $values) {
            if (!array_is_list($values) || count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException('Invalid or duplicate contract requirements/tests: ' . $this->contract);
            }
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException('Contract requirements/tests must contain nonempty strings.');
                }
            }
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
            trim((string) ($definition['version'] ?? '1.0.0')),
            trim((string) ($definition['supported_version_range'] ?? '*')),
            trim((string) ($definition['input_schema'] ?? '')),
            trim((string) ($definition['output_schema'] ?? '')),
            trim((string) ($definition['failure_semantics'] ?? '')),
            trim((string) ($definition['idempotency_semantics'] ?? '')),
            trim((string) ($definition['tenant_semantics'] ?? 'tenant_scoped')),
            (array) ($definition['authorization_requirements'] ?? []),
            trim((string) ($definition['deprecation_policy'] ?? '')),
            (array) ($definition['compatibility_tests'] ?? []),
        );
    }

    public function supportsVersion(string $providerVersion): bool
    {
        return VersionConstraint::matches($providerVersion, $this->supportedVersionRange);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'contract' => $this->contract, 'role' => $this->role,
            'counterpart' => $this->counterpart, 'kind' => $this->kind,
            'purpose' => $this->purpose, 'version' => $this->version,
            'supported_version_range' => $this->supportedVersionRange,
            'input_schema' => $this->inputSchema, 'output_schema' => $this->outputSchema,
            'failure_semantics' => $this->failureSemantics,
            'idempotency_semantics' => $this->idempotencySemantics,
            'tenant_semantics' => $this->tenantSemantics,
            'authorization_requirements' => $this->authorizationRequirements,
            'deprecation_policy' => $this->deprecationPolicy,
            'compatibility_tests' => $this->compatibilityTests,
        ];
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
