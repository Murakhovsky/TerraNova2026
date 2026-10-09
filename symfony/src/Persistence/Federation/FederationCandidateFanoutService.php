<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthDecisionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationCandidateFanoutPlanner;
use Platform\Orchestration\Goal\FederationProposalTemplateGuard;
use Platform\Documents\Contract\DocumentsRepositoryInterface;

/**
 * Human-triggered, provenance-checked 1..50 candidate PROPOSALS under one Goal.
 * Each resulting Plan still needs independent human Plan approval AND each
 * external Action still needs its normal per-Action approval.
 *
 * Does not dispatch, qualify, create Sales Leads or generate Documents.
 */
final readonly class FederationCandidateFanoutService
{
    public function __construct(
        private Connection $db,
        private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,
        private FederationExternalActionReceiptReconciler $receipts,
        private GrowthMarketDiscoveryBoundary $market,
        private GrowthApplicationBoundary $growth,
        private GrowthBuyingCommitteeRepositoryInterface $buyingCommittees,
        private GrowthDecisionRepositoryInterface $policies,
        private DocumentsRepositoryInterface $documents,
        private FederationCandidateFanoutPlanner $planner,
        private FederationProposalTemplateGuard $templates,
    ) {}

    /**
     * @param array<string,mixed> $options Required policy_id, policy_revision,
     * template_id, expected_value, recommended_play, recommended_action
     * @return array<string,mixed>
     */
    public function preview(
        TenantContext $actor,
        string $goalId,
        string $sourceRunId,
        string $sourceStepId,
        int $requested,
        array $options,
    ): array {
        self::manager($actor);
        foreach ([$goalId, $sourceRunId, $sourceStepId] as $id) self::id($id);
        if ($requested < 1 || $requested > FederationCandidateFanoutPlanner::MAX_CANDIDATES) {
            throw new DomainException('Requested fan-out exceeds approved per-batch limit.');
        }
        foreach (['growth.candidate.qualify', 'growth.handoff.prepare',
                  'growth.handoff.target.sales', 'documents.proposal.prepare'] as $id) {
            $this->bindings->requireExecutable($actor, $id);
        }
        if (!$this->bindings->isTenantModuleEnabled($actor, 'federation')
            || !$this->bindings->isTenantModuleEnabled($actor, 'sales')) {
            throw new DomainException('Federation and native Sales intake must be active for this tenant.');
        }
        $specification = $this->goals->specification($actor, $goalId);
        if ($specification === null) throw new DomainException('Tenant Goal not found.');
        // Existing Growth Domain mutations require an integer actor id; fail
        // before accepting 50 plans that cannot pass native Action handlers.
        if (!ctype_digit($specification->ownerId) || (int)$specification->ownerId < 1) {
            throw new DomainException('Growth fan-out requires a native numeric user actor identity.');
        }

        $org = $actor->organizationId()->value();
        $source = $this->db->fetchAssociative(
            'SELECT r.goal_id, r.state AS run_state, p.state AS plan_state,
                    p.spec_version, s.state AS step_state, s.capability_id,
                    s.side_effect_level, s.idempotency_key, s.result_reference
             FROM cos_federation_runs r
             JOIN cos_federation_plans p
               ON p.organization_id = r.organization_id AND p.plan_id = r.plan_id
             JOIN cos_federation_steps s
               ON s.organization_id = r.organization_id AND s.run_id = r.run_id
             WHERE r.organization_id = :org AND r.run_id = :run
               AND s.step_id = :step',
            ['org'=>$org, 'run'=>$sourceRunId, 'step'=>$sourceStepId],
        );
        if (!$source || $source['goal_id'] !== $goalId
            || $source['run_state'] !== 'completed'
            || $source['plan_state'] !== 'approved'
            || (int)$source['spec_version'] !== $specification->version
            || $source['step_state'] !== 'completed'
            || $source['capability_id'] !== 'growth.market.discovery'
            || $source['side_effect_level'] !== 'external'
            || !is_string($source['idempotency_key'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/',$source['idempotency_key'])) {
            throw new DomainException('Missing finalized, approved Federation Growth discovery evidence for this Goal.');
        }

        // The completed-step receipt path is strictly read-only. It also
        // independently re-attests Action provenance, policy and one attempt.
        $receipt = $this->receipts->reconcile($actor, $sourceRunId, $sourceStepId);
        $actionId = $receipt['action_id'] ?? null;
        if (($receipt['status'] ?? null) !== 'completed'
            || !is_string($actionId) || !preg_match('/^[a-f0-9]{32}$/', $actionId)
            || $source['result_reference'] !== 'action:' . $actionId) {
            throw new DomainException('Source Discovery has no attested single-attempt Action receipt.');
        }
        $action = $this->db->fetchAssociative(
            'SELECT type, status, target_type, target_id, idempotency_key
             FROM cos_actions WHERE organization_id = :org AND id = :action',
            ['org'=>$org, 'action'=>$actionId],
        );
        $key = 'fed:' . $source['idempotency_key'];
        if (!$action || $action['type'] !== 'growth.market.discovery'
            || $action['status'] !== 'COMPLETED'
            || $action['target_type'] !== 'growth_market_universe'
            || !is_string($action['target_id']) || $action['target_id'] === ''
            || $action['idempotency_key'] !== $key) {
            throw new DomainException('Native Growth discovery Action identity does not match the approved Step.');
        }
        $nativeRunId = 'GMRN-' . strtoupper(substr(hash('sha256',
            $org . ':market_run:' . $key), 0, 20));
        $brief = $this->market->universeBrief($org, $action['target_id']);
        if (($brief['universe']['target_domain'] ?? null) !== 'sales'
            || !is_array($brief['runs'] ?? null)
            || !is_array($brief['memberships'] ?? null)
            || count($brief['memberships']) > 200) {
            throw new DomainException('Unverified Sales-targeted Growth market universe snapshot.');
        }
        $native = null;
        foreach ($brief['runs'] as $run) {
            if (is_array($run) && ($run['run_id'] ?? null) === $nativeRunId) {
                $native = $run;
                break;
            }
        }
        if (!is_array($native)
            || ($native['universe_id'] ?? null) !== $action['target_id']
            || ($native['status'] ?? null) !== 'completed') {
            throw new DomainException('Canonical Growth discovery run receipt not found or not completed.');
        }
        $native['organization_id'] = $org;
        // Production preflight: a policy name and template ID in a form are
        // not evidence that either resource is usable for this tenant.
        $policy = $this->policies->viewPolicy(
            $org,(string)($options['policy_id'] ?? ''),(int)($options['policy_revision'] ?? 0),
        );
        if (!is_array($policy) || ($policy['status'] ?? null) !== 'active') {
            throw new DomainException('Qualification policy revision is not currently active.');
        }
        $template = $this->documents->findTemplate($org,(string)($options['template_id'] ?? ''));
        if (!is_array($template) || !(bool)($template['active'] ?? false)) {
            throw new DomainException('Tenant-owned active proposal template is unavailable.');
        }
        $options['source_federation_run'] = $sourceRunId;
        $options['source_federation_step'] = $sourceStepId;
        // Read-only eligibility must exclude candidates already proposed for
        // this tenant Goal, even when they are rediscovered in a later scan.
        $existingCandidates = $this->existingCandidates($org, $goalId);
        $preview = $this->planner->build(
            $specification, $native, $brief['memberships'],
            function (string $id) use ($org): ?array {
                $candidate = $this->growth->viewCandidate($org,$id);
                if (!is_array($candidate) || ($candidate['subject_type'] ?? null) !== 'account') {
                    return null;
                }
                $accountId = $candidate['subject_id'] ?? null;
                if (!is_string($accountId) || $accountId === '') return null;
                // SalesGrowthHandoffTarget requires exactly one champion with
                // email identity, linked to this same tenant-owned account.
                $assessment = $this->buyingCommittees->latestAssessment($org,$accountId);
                $champions = $assessment['champion_contact_ids'] ?? null;
                if (!is_array($champions) || count($champions) !== 1
                    || !is_string($champions[0] ?? null) || $champions[0] === '') {
                    return null;
                }
                $champion = $this->buyingCommittees->viewContact($org,$champions[0]);
                if (!is_array($champion)
                    || !$this->buyingCommittees->isContactLinked($org,$accountId,$champions[0])
                    || strtolower(trim((string)($champion['identity_type'] ?? ''))) !== 'email'
                    || filter_var((string)($champion['identity_value'] ?? ''),FILTER_VALIDATE_EMAIL) === false
                    || trim((string)($champion['full_name'] ?? '')) === '') {
                    return null;
                }
                $candidate['lead_name'] = trim((string)$champion['full_name']);
                $candidate['lead_email'] = trim((string)$champion['identity_value']);
                return $candidate;
            },
            $requested, $options, $existingCandidates,
        );
        // The native renderer only performs exact {{key}} replacement. Never
        // propose a document that would retain unknown or empty placeholders.
        $body = $template['body'] ?? null;
        if (!is_string($body)) {
            throw new DomainException('Invalid active Documents template body.');
        }
        $this->templates->assertResolvable($body, $preview['plans']);
        return [
            'goal_id' => $goalId,
            'source_run_id' => $sourceRunId,
            'source_step_id' => $sourceStepId,
            'native_discovery_run_id' => $nativeRunId,
            'existing_candidate_ids' => $existingCandidates,
        ] + $preview;
    }

    /**
     * All-or-nothing draft creation. Repeating the call for the same
     * candidate/source fails on deterministic Plan IDs, never creates
     * duplicate approved Plans or executes any external operation.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function propose(
        TenantContext $actor, string $goalId, string $sourceRunId,
        string $sourceStepId, int $requested, array $options,
    ): array {
        $preview = $this->preview($actor,$goalId,$sourceRunId,$sourceStepId,$requested,$options);
        if ($preview['ready'] !== true) {
            throw new DomainException('Insufficient scored, source-attested Candidates for the requested fan-out.');
        }
        $plans = $preview['plans'];
        $this->db->transactional(function () use ($actor,$goalId,$plans,$preview): void {
            $org = $actor->organizationId()->value();
            // Serialize proposals for this Goal. The per-call 50 cap alone is
            // insufficient: multiple source runs must not cause 100 Leads.
            $goalLock = $this->db->fetchOne(
                'SELECT goal_id FROM cos_federation_goals
                 WHERE organization_id = :org AND goal_id = :goal FOR UPDATE',
                ['org'=>$org,'goal'=>$goalId],
            );
            if ($goalLock === false) {
                throw new DomainException('Goal vanished during fan-out proposal.');
            }
            $actualExisting = $this->existingCandidates($org,$goalId);
            if ($actualExisting !== $preview['existing_candidate_ids']) {
                throw new DomainException('Concurrent candidate Plan proposal changed Goal capacity; preview must be repeated.');
            }
            if (count($actualExisting) + count($plans) > FederationCandidateFanoutPlanner::MAX_CANDIDATES) {
                throw new DomainException('Goal already has too many candidate Plans.');
            }
            foreach ($plans as $plan) {
                $this->goals->proposePlan(
                    $actor, $plan['plan_id'], $goalId,
                    $plan['steps'], 1, $plan['lineage'],
                );
            }
        });
        return [
            'goal_id'=>$goalId,
            'source_run_id'=>$sourceRunId,
            'created_proposed_plans'=>count($plans),
            'plan_ids'=>array_column($plans,'plan_id'),
            'state'=>'proposed_requires_independent_approval',
            'business_outcome_verified'=>false,
        ];
    }

    /**
     * Read-only, fail-closed identity snapshot of all prior fan-out plans.
     * The caller's tenant and Goal identity are never sourced from Plan JSON.
     *
     * @return list<string>
     */
    private function existingCandidates(string $organizationId, string $goalId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT plan_json FROM cos_federation_plans
             WHERE organization_id = :org AND goal_id = :goal ORDER BY plan_id',
            ['org'=>$organizationId,'goal'=>$goalId],
        );
        $seen = [];
        foreach ($rows as $row) {
            $snapshot = json_decode((string)$row['plan_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new DomainException('Invalid tenant Goal Plan snapshot.');
            }
            $lineage = $snapshot['lineage'] ?? null;
            if ($lineage === null) continue;
            if (!is_array($lineage) || ($lineage['schema_version'] ?? null) !== 1
                || !is_string($lineage['candidate_id'] ?? null)
                || trim($lineage['candidate_id']) === '') {
                throw new DomainException('Unrecognized fan-out Plan lineage; manual reconciliation required.');
            }
            $candidate = $lineage['candidate_id'];
            if (isset($seen[$candidate])) {
                throw new DomainException('Goal already has duplicated fan-out Candidate lineage.');
            }
            $seen[$candidate] = true;
        }
        $ids = array_keys($seen);
        sort($ids, SORT_STRING);
        if (count($ids) > FederationCandidateFanoutPlanner::MAX_CANDIDATES) {
            throw new DomainException('Goal candidate plan capacity exceeded.');
        }
        return $ids;
    }

    private static function manager(TenantContext $actor): void
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Federation fan-out requires authenticated tenant manager.');
        }
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/',$id)) {
            throw new DomainException('Invalid Federation ID.');
        }
    }
}
