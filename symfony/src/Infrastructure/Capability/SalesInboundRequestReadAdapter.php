<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Kernel\Tenant\Model\TenantContext;

/** Tenant-authorized read boundary; Sales remains the authoritative data owner. */
final readonly class SalesInboundRequestReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesProposalLeadReadModelInterface $leads) {}

    public function binding(): string
    {
        return 'query:sales.inbound.request.read';
    }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        foreach (['organization_id', 'tenant_id', 'actor_id', 'user_id'] as $forged) {
            if (array_key_exists($forged, $input)) {
                throw new DomainException('Sales request identity is server-owned: ' . $forged);
            }
        }
        $leadId = $input['lead_id'] ?? null;
        if (!is_int($leadId) || $leadId <= 0) {
            throw new DomainException('A positive lead_id is required.');
        }
        $tenant = $actor->organizationId()->value();
        $request = $this->leads->lead($tenant, $leadId);
        if ($request !== null && (string)($request['organization_id'] ?? '') !== $tenant) {
            throw new DomainException('Sales request read model violated tenant ownership.');
        }
        if ($request !== null) {
            // PDO may return integer identifiers as decimal strings. Reject
            // missing, malformed or unrelated rows rather than coercing them.
            $returnedId = $request['id'] ?? null;
            if ($returnedId !== $leadId && $returnedId !== (string) $leadId) {
                throw new DomainException('Sales request read model violated entity identity.');
            }
        }
        // Never expose the full Sales lead aggregate (contact PII, notes,
        // communications or activities) through the generic Capability API.
        $summary = $request === null ? null : [
            'id' => (int) $request['id'],
            'status' => (string) ($request['status'] ?? ''),
            'client_case_id' => isset($request['client_case_id']) && (int) $request['client_case_id'] > 0
                ? (int) $request['client_case_id'] : null,
            'property_id' => isset($request['property_id']) && (int) $request['property_id'] > 0
                ? (int) $request['property_id'] : null,
        ];
        return ['found' => $request !== null, 'request' => $summary];
    }
}
