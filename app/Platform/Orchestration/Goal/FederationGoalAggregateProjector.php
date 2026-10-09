<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Pure, bounded read-only roll-up of independently checked Candidate native
 * artifacts. A completed Run is not business success; every candidate needs
 * its original approved Goal Plan, four attested Action receipts, an accepted
 * Sales handoff, and a native Document attached to that exact Sales Lead.
 *
 * No approval, execution, side effect or Goal outcome is created here.
 */
final readonly class FederationGoalAggregateProjector
{
    public const LIMIT = 50;
    public const STAGES = ['qualify','prepare','handoff','proposal'];

    /**
     * @param list<array<string,mixed>> $subjects Native-proof snapshots, one
     *   row per candidate Plan, ordered by deterministic Plan ID
     * @return array<string,mixed>
     */
    public function project(GoalSpecification $goal, array $subjects): array
    {
        if (count($subjects) > self::LIMIT) {
            throw new DomainException('Goal aggregate exceeds bounded candidate limit.');
        }
        $seenCandidates = [];
        $seenAccounts = [];
        $seenRuns = [];
        $seenLeads = [];
        $seenDocuments = [];
        $states = [
            'proposed' => 0, 'approved' => 0, 'running' => 0,
            'completed' => 0, 'blocked' => 0,
        ];
        $stage = array_fill_keys(self::STAGES, 0);
        $nativeHandoffs = 0;
        $verifiedProposals = 0;
        $items = [];
        foreach ($subjects as $subject) {
            $candidate = $subject['candidate_id'] ?? null;
            $account = $subject['account_id'] ?? null;
            $plan = $subject['plan_id'] ?? null;
            $state = $subject['plan_state'] ?? null;
            $run = $subject['run_id'] ?? null;
            $runState = $subject['run_state'] ?? null;
            $receipts = $subject['verified_actions'] ?? null;
            if (!is_string($candidate) || $candidate === ''
                || !is_string($account) || $account === ''
                || !is_string($plan) || $plan === ''
                || !in_array($state,['proposed','approved'],true)
                || !is_array($receipts)
                || array_keys($receipts) !== self::STAGES
                || !is_bool($subject['native_handoff_verified'] ?? null)
                || !is_bool($subject['native_proposal_verified'] ?? null)
                || ($run !== null && (!is_string($run) || $run === ''))
                || ($run === null && $runState !== null)
                || ($state === 'proposed' && $run !== null)
                || ($run !== null && !in_array($runState,['pending','running','waiting','completed','failed','cancelled','ambiguous'],true))) {
                throw new DomainException('Untrusted or malformed Goal candidate aggregate snapshot.');
            }
            $expectedId = 'plan-' . substr(hash('sha256',
                $goal->organizationId . "\0" . $goal->goalId . "\0" . $candidate
            ),0,24);
            if ($plan !== $expectedId || isset($seenCandidates[$candidate])
                || isset($seenAccounts[$account]) || ($run !== null && isset($seenRuns[$run]))) {
                throw new DomainException('Duplicate or mismatched Goal candidate lineage.');
            }
            $seenCandidates[$candidate] = true;
            $seenAccounts[$account] = true;
            if ($run !== null) $seenRuns[$run] = true;

            $fullChain = true;
            foreach (self::STAGES as $step) {
                if (!is_bool($receipts[$step])) {
                    throw new DomainException('Attested Action receipt must be boolean.');
                }
                if ($receipts[$step]) ++$stage[$step];
                else $fullChain = false;
            }
            $handoff = $subject['native_handoff_verified'];
            $proposal = $subject['native_proposal_verified'];
            $leadId = $subject['sales_lead_id'] ?? null;
            $documentId = $subject['document_id'] ?? null;
            if ($handoff && (!$fullChain || $runState !== 'completed'
                || !is_string($leadId) || !ctype_digit($leadId) || (int)$leadId < 1)) {
                throw new DomainException('Native Sales outcome has no attested completed Action chain.');
            }
            if ($proposal && (!$handoff || !is_string($documentId) || $documentId === '')) {
                throw new DomainException('Proposal is not linked to an independently verified Sales handoff.');
            }
            if ($handoff) {
                if (isset($seenLeads[$leadId])) {
                    throw new DomainException('One native Sales Lead was credited to multiple Candidates.');
                }
                $seenLeads[$leadId] = true;
                ++$nativeHandoffs;
            }
            if ($proposal) {
                if (isset($seenDocuments[$documentId])) {
                    throw new DomainException('One Document was credited to multiple Candidates.');
                }
                $seenDocuments[$documentId] = true;
                ++$verifiedProposals;
            }
            if ($state === 'proposed') ++$states['proposed'];
            else ++$states['approved'];
            if ($runState === 'completed') ++$states['completed'];
            elseif ($runState === 'failed' || $runState === 'cancelled' || $runState === 'ambiguous') ++$states['blocked'];
            elseif ($run !== null) ++$states['running'];
            $items[] = [
                'plan_id' => $plan,'candidate_id' => $candidate,
                'plan_state' => $state,'run_id' => $run,'run_state' => $runState,
                'verified_action_count' => count(array_filter($receipts)),
                'native_sales_handoff_verified' => $handoff,
                'native_proposal_verified' => $proposal,
                'sales_lead_id' => $handoff ? $leadId : null,
                'document_id' => $proposal ? $documentId : null,
            ];
        }
        $target = null;
        // This read-only metric measures prepared and attached proposals, NOT
        // signed contracts, won deals, correspondence or revenue.
        if (count($goal->criteria) === 1
            && $goal->criteria[0]['id'] === 'federation.verified_proposals'
            && $goal->criteria[0]['operator'] === 'at_least'
            && is_int($goal->criteria[0]['expected'])
            && $goal->criteria[0]['expected'] >= 1) {
            $target = $goal->criteria[0]['expected'];
        }
        $fullyAttested = $subjects !== [] && $verifiedProposals === count($subjects)
            && $states['completed'] === count($subjects);
        $criterionMet = $target !== null && $target > 0 && $verifiedProposals >= $target;
        return [
            'schema_version' => 1,
            'goal_id' => $goal->goalId,
            'organization_id' => $goal->organizationId,
            'specification_version' => $goal->version,
            'candidate_plans' => count($subjects),
            'plan_states' => $states,
            'verified_action_steps' => $stage,
            'native_sales_handoffs' => $nativeHandoffs,
            'native_proposals_prepared' => $verifiedProposals,
            'metric' => 'federation.verified_proposals',
            'criterion_target' => $target,
            'criterion_threshold_observed' => $criterionMet,
            // A projection cannot mint a canonical, persisted Goal evaluation.
            'business_outcome_verified' => false,
            'aggregate_state' => $fullyAttested && $criterionMet
                ? 'ready_for_independent_goal_evaluation'
                : ($states['blocked'] > 0 ? 'needs_human_intervention' : 'in_progress_or_unverifiable'),
            'items' => $items,
        ];
    }
}
