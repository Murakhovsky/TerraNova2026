<?php
declare(strict_types=1);
namespace App\Application\Capability\Contract;

use Kernel\Module\CapabilityContract;
use Kernel\Tenant\Model\TenantContext;

/** Additional owner-domain authorization at canonical direct invocation boundary. */
interface CapabilityInvocationPolicyInterface
{
    public function supports(string $owner):bool;
    public function authorize(TenantContext $actor,CapabilityContract $contract,array $input):void;
}
