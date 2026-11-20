<?php
declare(strict_types=1);
namespace App\Infrastructure\Experience\Business;

use App\Application\Experience\Business\Contract\BusinessEntitySummaryReadInterface;
use Domains\Property\Contract\PropertyReferencePort;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;

/**
 * Projection only. Each existing Domain read model authorizes by organization.
 * Caller still needs a tenant-verified SearchResult before selecting an entity.
 */
final readonly class TenantBusinessEntitySummaryRead implements BusinessEntitySummaryReadInterface
{
    public function __construct(
        private SalesWorkspaceReadModelInterface $sales,
        private PropertyReferencePort $properties,
    ) {}

    public function facts(string $organizationId,string $entityType,string $entityId): array
    {
        if ($organizationId==='' || $entityId==='') return [];
        if ($entityType==='property.asset') {
            if (preg_match('/^PROP-[A-F0-9]{20}$/D',$entityId)!==1) return [];
            $snapshot=$this->properties->getPropertyPresentation($organizationId,$entityId);
            if (!is_array($snapshot)) return [];
            $property=is_array($snapshot['property']??null) ? $snapshot['property'] : [];
            if (($property['asset_id']??null)!==$entityId) return [];
            $inventory=is_array($snapshot['inventory']??null) ? $snapshot['inventory'] : [];
            return $this->collect([
                'uw.facts.type'=>$property['type_code']??$property['kind']??null,
                'uw.facts.location'=>$property['formatted_address']??$property['location_name']??null,
                'uw.facts.status'=>$inventory['status']??$property['lifecycle']??null,
            ]);
        }
        if (!in_array($entityType,['sales.deal','sales.lead'],true)
            || preg_match('/^[1-9][0-9]{0,9}$/D',$entityId)!==1) return [];
        $id=(int)$entityId;
        $record=$entityType==='sales.deal'
            ? $this->sales->deal($organizationId,$id)
            : $this->sales->lead($organizationId,$id);
        if (!is_array($record) || (int)($record['id']??0)!==$id) return [];
        return $this->collect([
            'uw.facts.status'=>$record['status']??null,
            'uw.facts.stage'=>$record['stage_name']??$record['stage']??null,
            'uw.facts.owner'=>$record['owner_name']??null,
            'uw.facts.next_step'=>$record['next_action_at']??$record['next_contact_at']??null,
        ]);
    }

    /** @param array<string,mixed> $data
     *  @return list<array{label:string,value:string}> */
    private function collect(array $data): array
    {
        $facts=[];
        foreach ($data as $label=>$value) {
            if (!is_scalar($value) || is_bool($value)) continue;
            $display=trim((string)$value);
            if ($display==='' || mb_strlen($display)>240) continue;
            $facts[]=['label'=>$label,'value'=>$display];
        }
        return $facts;
    }
}
