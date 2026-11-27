<?php
declare(strict_types=1);

namespace App\Web\Experience\Unified\Business;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use DomainException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;

/** A read-only presentation adapter; the gateway remains the sole invocation authority. */
final readonly class WorkspaceCapabilityRead
{
    public function __construct(
        private CanonicalCapabilityGateway $gateway,
        private TenantContextProviderInterface $tenants,
    ) {}

    public function query(WebExtensionContext $context, string $capability, array $input): array
    {
        $actor=$this->actor($context);
        $description = $this->gateway->describe($actor, $capability);
        if (($description['status'] ?? null) !== 'read_only'
            || ($description['contract']['kind'] ?? null) !== 'query'
            || ($description['contract']['side_effect_level'] ?? null) !== 'none') {
            throw new DomainException('Workspace requires an authorized read-only query.');
        }

        $value = $this->gateway->invoke($actor, $capability, $input, 'trusted_read', '', CorrelationId::generate()->value());
        if (!is_array($value)) {
            throw new DomainException('Invalid Domain readback.');
        }
        return $value;
    }

    /** Present a human-only command only when its canonical binding is available.
     * This is not an execution permission; POST reauthorizes and executes via gateway.
     */
    public function offersHumanCommand(WebExtensionContext $context,string $capability):bool
    {
        try {
            return ($this->gateway->describe($this->actor($context),$capability)['status']??null)==='direct_human';
        } catch (DomainException) {
            return false;
        }
    }

    /** Availability checks are metadata only; the exact source read is repeated on POST. */
    public function offersReadQuery(WebExtensionContext $context,string $capability):bool
    {
        try {
            $entry=$this->gateway->describe($this->actor($context),$capability);
            return ($entry['status']??null)==='read_only'
                &&($entry['contract']['kind']??null)==='query'
                &&($entry['contract']['side_effect_level']??null)==='none';
        } catch (DomainException) {
            return false;
        }
    }

    private function actor(WebExtensionContext $context):\Kernel\Tenant\Model\TenantContext
    {
        $actor = $this->tenants->current();
        if ($actor === null || $actor->organizationId()->value() !== $context->organizationId
            || $actor->role()->value() !== $context->role || !$actor->isManager()
            || !$actor->allows(TenantPermissions::ACCESS) || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Workspace read unavailable.');
        }

        return $actor;
    }

    public static function hit(string $type, string $id, string $label, string $path, string $subtitle = '', ?string $rowId = null): SearchResult
    {
        return new SearchResult($type.'.'.($rowId ?? $id), $label, $path, 'entity', $subtitle, new EntityRef($type, $id), 90.0);
    }

    public static function workspacePath(string $workspace, ?EntityRef $entity = null): string
    {
        $path = '/workspace/unified/business/'.rawurlencode($workspace);
        return $entity === null ? $path : $path.'?'.http_build_query(['q'=>$entity->id, 'selected'=>$entity->key()], '', '&', PHP_QUERY_RFC3986);
    }

    /** Only explicitly named, scalar display fields are projected. No secret/JSON dump. */
    public static function facts(array $row, array $fields): array
    {
        $facts=[];
        foreach ($fields as $key=>$label) {
            $value=$row[$key]??null;
            if (!is_scalar($value) || is_bool($value) || (string)$value==='') continue;
            $facts[]=['label'=>$label,'value'=>mb_substr((string)$value,0,240)];
        }
        return $facts;
    }

    /** Bounded, presentation-only tables from independently read native records. */
    public static function table(string $title, array $fields, array $rows, ?string $notice = null): array
    {
        $result=[];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) continue;
            $line=[];
            foreach ($fields as $key=>$label) {
                $value=$row[$key]??null;
                $line[]=is_scalar($value)&&!is_bool($value)?mb_substr((string)$value,0,240):'—';
            }
            $result[]=$line;
        }
        return ['title'=>$title,'columns'=>array_values($fields),'rows'=>$result,'notice'=>$notice];
    }
}
