<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRunStore;
use Domains\Marketing\Application\Service\MarketingAnalystContextService;
use Domains\Marketing\Application\Service\MarketingAnalystExecutionService;
use Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Module\ActiveModuleResolver;
use Platform\Integration\Contract\CredentialVaultInterface;

/** Composition root for the future approval-bound Action handler only. */
final readonly class HermesAnalystExecutionFactory
{
    public function __construct(private HermesAnalystConfigurationRegistry $profiles,private MarketingAnalystContextService $contexts,
        private MarketingAnalystRunStore $runs,private CredentialVaultInterface $vault,private HermesAnalystTransport $transport,
        private LlmGovernanceRepositoryInterface $governance,private ActiveModuleResolver $modules){}

    public function create(string $org,string $approvedConfigurationHash):MarketingAnalystExecutionService
    {
        $configuration=$this->profiles->forTenant($org);
        if($configuration===null||!hash_equals($configuration->configurationHash,$approvedConfigurationHash)){
            throw new DomainException('Hermes configuration changed or was not approved.');
        }
        $configuration->assertAccepted($org);
        $policy=new MarketingAnalystRecommendationPolicy();
        $adapter=new HermesMarketingAnalystAdapter($this->contexts,$configuration->connection,$this->vault,$this->transport,$policy);
        $runtime=new KernelBudgetedHermesAnalystRuntime($adapter,$configuration,$this->governance);
        return new MarketingAnalystExecutionService($this->contexts,$runtime,$this->runs,$configuration->admission,$policy,$this->modules);
    }
}
