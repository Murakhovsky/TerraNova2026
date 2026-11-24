<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\MarketingSpendEvidenceReadPort;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * Trusted in-process readback of Marketing source evidence into Finance's OWN
 * observation ledger. No external API write, invoice, expense or budget mutation.
 *
 * Not exposed as a public Capability/HTTP endpoint until canonical Workflow
 * provenance, replay and two-module staging acceptance are completed.
 */
final readonly class FinanceMarketingSpendReadbackService
{
    public function __construct(
        private MarketingSpendEvidenceReadPort $source,
        private FinanceSpendObservationService $finance,
    ){}

    /** @return array<string,mixed> */
    public function import(TenantContext $actor,string $accountId,string $snapshotId,string $correlationId):array
    {
        if(!$actor->isManager()
            ||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Manager authorization required for source evidence readback.');
        }
        if(!preg_match('/^mads-[a-f0-9]{24}$/D',$accountId)
            ||!preg_match('/^mis-[a-f0-9]{24}$/D',$snapshotId)
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$correlationId)){
            throw new DomainException('Invalid Finance marketing source reference or audit correlation.');
        }

        // Finance requests its own evidence from Marketing's current tenant-scoped
        // readback port, never accepts caller-supplied spend, scope or source hashes.
        $evidence=$this->source->get($actor,$accountId,$snapshotId);
        $fields=[
            'ad_account_ref','source_snapshot_ref','grain','external_id','window_start','window_end',
            'timezone','currency','attribution_hash','source_hash','source_revision',
            'source_status','reported_spend_decimal'
        ];
        if(array_keys($evidence)!==$fields
            ||$evidence['ad_account_ref']!==$accountId
            ||$evidence['source_snapshot_ref']!==$snapshotId
            ||$evidence['grain']!=='campaign'){
            throw new DomainException('Marketing evidence readback shape or tenant/account scope cannot be trusted.');
        }
        $result=$this->finance->acceptUnverifiedMarketingSnapshot(
            $actor->organizationId()->value(),$evidence,$correlationId);
        return [
            'status'=>'SOURCE_OBSERVATION_UNRECONCILED',
            'observation'=>$result,
            'readback_status'=>'MARKETING_DB_SNAPSHOT',
            'source_provenance_verified_by_provider'=>false,
            'finance_expense_confirmed'=>false,
            'budget_mutated'=>false,
        ];
    }
}
