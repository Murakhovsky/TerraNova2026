<?php
declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use DomainException;
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
    ) {}

    public function supports(UIContext $context): bool
    {
        return $context->workspace === 'marketing.campaigns'
            && ($context->entity === null || $context->entity->type === 'marketing_campaign');
    }

    public function inspect(TenantContext $actor, UIContext $context): array
    {
        if (!AgentWorkspaceAccess::allows($actor) || !$this->supports($context)) {
            throw new DomainException('Agent runtime scope denied.');
        }

        try {
            // Checks tenant manager and active module, does not resolve secrets or call Hermes.
            $status = $this->readiness->status($actor);
            $runtime = [
                'status' => $status['status'],
                'max_cost_usd' => $status['max_cost_usd'],
                'daily_run_limit' => $status['daily_run_limit'],
                'plan_available' => $status['plan_available'] === true,
                'live_verified' => false,
                'tool_limit' => 0,
                'retry_limit' => 0,
                'request_timeout_seconds' => 45,
            ];
            if ($context->entity === null) {
                $choices = [];
                foreach ($this->campaigns->search($actor, '', 50) as $campaign) {
                    $choices[] = ['id' => $campaign['id'], 'name' => $campaign['name']];
                }

                return ['runtime' => $runtime, 'choices' => $choices, 'campaign' => null];
            }

            // The user-supplied EntityRef is not proof of ownership.
            $campaign = $this->campaigns->get($actor, $context->entity->id);
            if ($campaign === null) {
                throw new DomainException('Campaign scope unavailable.');
            }
            return [
                'runtime' => $runtime,
                'choices' => [],
                'campaign' => [
                    'id' => $campaign['id'], 'name' => $campaign['name'],
                    'status' => $campaign['status'], 'revision' => $campaign['revision'],
                ],
                'creatives' => $this->planning->creatives($actor, $campaign['id']),
                'adsets' => $this->planning->adsets($actor, $campaign['id']),
                'runs' => $this->reviews->listRuns($actor, $campaign['id'], 50, 0),
            ];
        } catch (Throwable) {
            // No exception text, credentials, profile URLs or external response bodies.
            // Intentionally indistinguishable from a missing/foreign scope.
            return [
                'runtime' => ['status' => 'UNAVAILABLE', 'max_cost_usd' => null,
                    'daily_run_limit' => null, 'plan_available' => false, 'live_verified' => false,
                    'tool_limit' => 0, 'retry_limit' => 0, 'request_timeout_seconds' => 45],
                'choices' => [], 'campaign' => null,
            ];
        }
    }
}
