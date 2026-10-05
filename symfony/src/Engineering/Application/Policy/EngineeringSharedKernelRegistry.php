<?php
declare(strict_types=1);

namespace App\Engineering\Application\Policy;

final class EngineeringSharedKernelRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function primitives(): array
    {
        return [
            'Money' => [
                'symbol' => 'Kernel\\Shared\\Domain\\Money',
                'path' => 'app/Kernel/Shared/Domain/Money.php',
                'representation' => 'VALUE_OBJECT',
            ],
            'Currency' => [
                'symbol' => 'Kernel\\Shared\\Domain\\Money::currency',
                'path' => 'app/Kernel/Shared/Domain/Money.php',
                'representation' => 'MONEY_VALUE',
            ],
            'Identifier' => [
                'symbol' => 'Kernel\\Shared\\Domain\\Identifier',
                'path' => 'app/Kernel/Shared/Domain/Identifier.php',
                'representation' => 'BASE_VALUE_OBJECT',
            ],
            'Clock' => [
                'symbol' => 'Kernel\\Shared\\Time\\Clock',
                'path' => 'app/Kernel/Shared/Time/Clock.php',
                'representation' => 'INTERFACE',
            ],
            'TenantId' => [
                'symbol' => 'Kernel\\Shared\\Domain\\OrganizationId',
                'path' => 'app/Kernel/Shared/Domain/OrganizationId.php',
                'representation' => 'TENANT_IDENTIFIER',
            ],
            'UserId' => [
                'symbol' => 'Kernel\\Shared\\Domain\\UserId',
                'path' => 'app/Kernel/Shared/Domain/UserId.php',
                'representation' => 'IDENTIFIER',
            ],
            'DomainEvent' => [
                'symbol' => 'Kernel\\Shared\\Domain\\DomainEvent',
                'path' => 'app/Kernel/Shared/Domain/DomainEvent.php',
                'representation' => 'DOMAIN_EVENT_BASE',
            ],
        ];
    }

    public function reuseRule(): string
    {
        return 'Reuse canonical Shared Kernel primitives before creating Domain-specific equivalents. Shared Kernel expansion requires explicit architecture justification.';
    }

    public function containsConcept(string $concept): bool
    {
        return array_key_exists(trim($concept), $this->primitives());
    }
}
