<?php
declare(strict_types=1);
namespace App\Application\Capability\Contract;
use Kernel\Tenant\Model\TenantContext;
/** Explicit Domain bus binding, not a second command or workflow executor. */
interface CapabilityInvocationAdapterInterface {
    public function binding(): string;
    /** @param array<string,mixed> $input */
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId): mixed;
}
