<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Throwable;

/**
 * First business runtime binding: Marketing's already governed Hermes analyst.
 * No Engineering profile, user-supplied URL, credential or unrestricted tool bundle.
 * Owner APIs recheck the actor, enabled module, campaign and pinned revisions.
 */
final readonly class HermesWorkspaceAdapter implements AgentRuntimeAdapterInterface
{
    public function __construct(
        private HermesAnalystReadinessService $readiness,
        private MarketingCampaignService $campaigns,
        private MarketingPlanningService $planning,
        private MarketingAnalystReviewService $reviews,
        private ActiveModuleResolver $modules,
    ) {}

    public function supports(UIContext $context): bool
    {
        return $context->workspace === 'marketing.campaigns'
            && ($context->entity === null || $context->entity->type === 'marketing_campaign');
    }

    public function inspect(TenantContext $actor, UIContext $context): AgentRuntimeInspection
    {
        if (!AgentWorkspaceAccess::allows($actor) || !$this->supports($context)) {
            throw new DomainException('Agent runtime scope denied.');
        }

        $binding = [
            'runtime' => ['status' => 'UNAVAILABLE', 'max_cost_usd' => null,
                'daily_run_limit' => null, 'plan_available' => false, 'live_verified' => false,
                'tool_limit' => 0, 'retry_limit' => 0, 'request_timeout_seconds' => 45,
                'token_budget_verified' => false, 'token_limits' => null, 'configuration_hash' => null, 'remote_cancel_available' => false],
            'choices' => [], 'campaign' => null, 'creatives' => [], 'adsets' => [], 'runs' => [],
            'choices_available' => false, 'planning_available' => false, 'journal_available' => false,
        ];

        try {
            // Resolve module and owner scope before any readiness or journal read.
            // No distinction between a denied, absent or failed ownership lookup.
            if (!$this->modules->isEnabled($actor->organizationId()->value(), 'marketing')) {
                throw new DomainException('Marketing scope unavailable.');
            }
            if ($context->entity !== null) {
                $campaign = $this->campaigns->get($actor, $context->entity->id);
                if ($campaign === null) {
                    throw new DomainException('Campaign scope unavailable.');
                }
                $binding['campaign'] = [
                    'id' => $campaign['id'], 'name' => $campaign['name'],
                    'status' => $campaign['status'], 'revision' => $campaign['revision'],
                ];
            }
        } catch (Throwable) {
            return new AgentRuntimeInspection(false, 'hermes_marketing', $binding);
        }

        // The remaining reads are independent. A provider/readiness outage must
        // not disguise itself as denied ownership or hide readable native evidence.
        try {
            $status = $this->readiness->status($actor);
            $binding['runtime']['status'] = $status['status'];
            $binding['runtime']['max_cost_usd'] = $status['max_cost_usd'];
            $binding['runtime']['daily_run_limit'] = $status['daily_run_limit'];
            $binding['runtime']['token_limits'] = $status['token_limits'];
            $binding['runtime']['configuration_hash'] = $status['configuration_hash'];
            // Older Marketing profiles still work in their owner flow, but the
            // new AI Workspace requires a declared exact token contract.
            $binding['runtime']['plan_available'] = $status['plan_available'] === true
                && $status['token_limits'] !== null
                && is_string($status['configuration_hash']);
        } catch (Throwable) {
            // Keep UNAVAILABLE, never display exception text or provider metadata.
        }

        if ($binding['campaign'] === null) {
            try {
                $choices = [];
                foreach ($this->campaigns->search($actor, '', 50) as $campaign) {
                    $choices[] = ['id' => $campaign['id'], 'name' => $campaign['name']];
                }
                $binding['choices'] = $choices;
                $binding['choices_available'] = true;
            } catch (Throwable) {
                $binding['runtime']['plan_available'] = false;
            }

            return new AgentRuntimeInspection(true, 'hermes_marketing', $binding);
        }

        $campaignId = $binding['campaign']['id'];
        try {
            $creatives = $this->planning->creatives($actor, $campaignId);
            $adsets = $this->planning->adsets($actor, $campaignId);
            $binding['creatives'] = $creatives;
            $binding['adsets'] = $adsets;
            $binding['planning_available'] = true;
        } catch (Throwable) {
            // No partial planning projection or new proposal when evidence is missing.
            $binding['runtime']['plan_available'] = false;
        }
        try {
            $binding['runs'] = $this->reviews->listRuns($actor, $campaignId, 50, 0);
            $binding['journal_available'] = true;
        } catch (Throwable) {
            // Unknown journal state is not an empty journal; suppress delegation.
            $binding['runtime']['plan_available'] = false;
        }

        return new AgentRuntimeInspection(true, 'hermes_marketing', $binding);
    }
}
