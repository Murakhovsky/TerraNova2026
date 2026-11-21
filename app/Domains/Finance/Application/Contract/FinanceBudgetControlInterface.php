<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/**
 * Only internal draft and read are callable by the Capability Gateway.
 * Paid financial transitions require the separate approved Kernel Action handlers.
 */
interface FinanceBudgetControlInterface
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createDraft(TenantContext $actor,array $input,string $key):array;
    /** @return array<string,mixed>|null */
    public function envelope(TenantContext $actor,string $envelopeId):?array;
    /** @return list<array<string,mixed>> */
    public function reservations(TenantContext $actor,string $envelopeId):array;
}

