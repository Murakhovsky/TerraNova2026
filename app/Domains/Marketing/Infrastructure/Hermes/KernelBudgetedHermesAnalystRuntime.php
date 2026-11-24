<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRuntimePort;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Llm\LlmUsageRecord;
use Kernel\Tenant\Model\TenantContext;

/**
 * Conservatively consumes the approved maximum allowance BEFORE a remote turn.
 * The common Kernel LLM ledger owns the allowance, not Marketing or Finance.
 * This is deliberately not a provider invoice or a measured token cost.
 */
final readonly class KernelBudgetedHermesAnalystRuntime implements MarketingAnalystRuntimePort
{
    public function __construct(private MarketingAnalystRuntimePort $runtime,private HermesAnalystRuntimeConfiguration $configuration,
        private LlmGovernanceRepositoryInterface $governance){}

    public function recommend(TenantContext $actor,array $envelope,string $correlationId):array
    {
        $org=$actor->organizationId()->value();
        $this->configuration->assertAccepted($org);
        if(!preg_match('/^mkar-[a-f0-9]{24}$/D',$correlationId))throw new DomainException('Durable analyst receipt required for budget admission.');
        $limit=$this->configuration->maxCostUsd;
        if($this->governance->monthlyBudget($org,'USD')===null)throw new DomainException('Explicit tenant LLM budget required.');
        $reservation=$this->governance->reserveBudget($org,$limit,'USD');
        // Persist the ceiling as a conservative budget usage estimate. No automatic
        // refund, even if the remote call times out. If persistence fails, no call.
        $this->governance->settleBudget($reservation,new LlmUsageRecord(
            id:substr(hash('sha256',$org.':hermes:'.$correlationId),0,32),
            organizationId:$org,correlationId:$correlationId,useCase:'marketing.analyst.run',
            provider:'hermes',model:'hermes-agent',inputTokens:null,outputTokens:null,
            costAmount:$limit,costCurrency:'USD',latencyMs:0,fallbackCount:0,
            costSource:'AUTHORIZED_CEILING',pricingVersion:substr($this->configuration->configurationHash,0,32)
        ));
        return $this->runtime->recommend($actor,$envelope,$correlationId);
    }
}
