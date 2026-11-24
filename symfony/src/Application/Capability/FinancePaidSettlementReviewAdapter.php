<?php
declare(strict_types=1);
namespace App\Application\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Finance\Application\Service\FinancePaidSettlementReviewService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;

/** Canonical Gateway admits human commands only. This records evidence, not financial authority. */
final readonly class FinancePaidSettlementReviewAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private FinancePaidSettlementReviewService $service){}
    public function binding():string{return 'command:finance.ad_spend.settlement.review';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        if(($input['confirm_review']??null)!==true)throw new DomainException('Explicit human document review required.');
        return $this->service->review($actor,$input['reservation_id'],$input['document_ref'],$input['snapshot_ref'],
            $input['document_hash'],$input['source_hash'],$input['source_revision'],$input['provider_document_id'],$input['settlement_minor'])['candidate'];
    }
}
