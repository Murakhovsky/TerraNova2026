<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Deterministic, read-only and bounded generation of candidate-specific
 * PROPOSED plans. Never creates a candidate, Action, Approval or Sales Lead.
 *
 * Growth market discovery is account discovery, not proof that Candidates exist.
 * Only linked, scored, Sales-targeted candidates qualify for this stage.
 */
final readonly class FederationCandidateFanoutPlanner
{
    public const MAX_CANDIDATES = 50;

    private const REQUIRED = [
        'growth.candidate.qualify',
        'growth.handoff.prepare',
        'growth.handoff.target.sales',
        'documents.proposal.prepare',
    ];

    /**
     * @param array<string,mixed> $run Verified native Growth discovery run
     * @param list<array<string,mixed>> $memberships Growth-owned membership snapshot
     * @param callable(string):?array<string,mixed> $viewCandidate Tenant-scoped Growth boundary
     * @param array<string,mixed> $options Human-selected bounded policy/template inputs
     * @return array<string,mixed>
     */
    public function build(
        GoalSpecification $goal,
        array $run,
        array $memberships,
        callable $viewCandidate,
        int $requested,
        array $options,
    ): array {
        if ($requested < 1 || $requested > self::MAX_CANDIDATES) {
            throw new DomainException('Fan-out size must be between 1 and 50.');
        }
        if (($run['status'] ?? null) !== 'completed'
            || ($run['organization_id'] ?? null) !== $goal->organizationId
            || !is_string($run['run_id'] ?? null) || $run['run_id'] === ''
            || !is_string($run['universe_id'] ?? null) || $run['universe_id'] === '') {
            throw new DomainException('Fan-out requires a completed, tenant-owned native Growth discovery run.');
        }
        if (count($memberships) > 200) {
            throw new DomainException('Unbounded Growth membership snapshot is not allowed.');
        }
        $start = self::instant($run['started_at'] ?? null);
        $end = self::instant($run['finished_at'] ?? null);
        if ($start === null || $end === null || $start > $end) {
            throw new DomainException('Native discovery source window is unverified.');
        }
        foreach (self::REQUIRED as $id) {
            if (!in_array($id, $goal->allowedCapabilities, true)) {
                throw new DomainException('Goal does not authorize proposed capability: ' . $id);
            }
        }
        foreach ([
            'policy_id' => 80, 'template_id' => 80,
            'expected_value' => 500, 'recommended_play' => 191,
            'recommended_action' => 1000,
        ] as $key => $max) {
            if (!is_string($options[$key] ?? null) || trim($options[$key]) === ''
                || mb_strlen($options[$key]) > $max) {
                throw new DomainException('Missing or invalid approved fan-out option: ' . $key);
            }
        }
        if (!is_int($options['policy_revision'] ?? null) || $options['policy_revision'] < 1) {
            throw new DomainException('Qualification requires a positive policy revision.');
        }
        if (!is_string($options['source_federation_run'] ?? null)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $options['source_federation_run'])
            || !is_string($options['source_federation_step'] ?? null)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $options['source_federation_step'])) {
            throw new DomainException('Fan-out requires trusted Federation discovery provenance.');
        }

        $eligible = [];
        $seenCandidates = [];
        $seenAccounts = [];
        $seenSources = [];
        foreach ($memberships as $row) {
            if (!is_array($row)
                || ($row['organization_id'] ?? null) !== $goal->organizationId
                || ($row['universe_id'] ?? null) !== $run['universe_id']) {
                throw new DomainException('Cross-tenant or cross-universe candidate source rejected.');
            }
            $candidateId = $row['candidate_id'] ?? null;
            if (!is_string($candidateId) || $candidateId === '') {
                continue; // Discovered accounts are NOT necessarily opportunities.
            }
            $accountId = $row['account_id'] ?? null;
            $source = $row['source_reference'] ?? null;
            $sourceHash = $row['external_key_hash'] ?? null;
            $seenAt = self::instant($row['last_seen_at'] ?? null);
            if (!is_string($accountId) || $accountId === ''
                || !is_string($source) || trim($source) === ''
                || !is_string($sourceHash) || !preg_match('/^[a-f0-9]{64}$/', $sourceHash)
                || $seenAt === null || $seenAt < $start || $seenAt > $end) {
                continue; // Never use unproven or stale discovery membership.
            }
            if (isset($seenCandidates[$candidateId]) || isset($seenAccounts[$accountId])
                || isset($seenSources[$sourceHash])) {
                continue;
            }
            $candidate = $viewCandidate($candidateId);
            if (!is_array($candidate)
                || ($candidate['organization_id'] ?? null) !== $goal->organizationId
                || ($candidate['candidate_id'] ?? null) !== $candidateId
                || ($candidate['subject_type'] ?? null) !== 'account'
                || ($candidate['subject_id'] ?? null) !== $accountId
                || ($candidate['target_domain'] ?? null) !== 'sales'
                || ($candidate['status'] ?? null) !== 'scored'
                || !is_array($candidate['score'] ?? null)
                || !is_array($candidate['rationale'] ?? null)) {
                continue;
            }
            $seenCandidates[$candidateId] = true;
            $seenAccounts[$accountId] = true;
            $seenSources[$sourceHash] = true;
            $eligible[] = [
                'candidate_id' => $candidateId,
                'account_id' => $accountId,
                'source_hash' => $sourceHash,
                'source_reference' => $source,
            ];
        }
        usort($eligible, static fn (array $a, array $b): int =>
            strcmp($a['candidate_id'], $b['candidate_id']));
        $ready = count($eligible) >= $requested;
        // Never silently reduce the desired business result.
        $selected = $ready ? array_slice($eligible, 0, $requested) : [];
        $plans = [];
        foreach ($selected as $subject) {
            $id = $subject['candidate_id'];
            // One Candidate may reappear in later discovery runs. Never
            // mint a second Plan for the same tenant+Goal+Candidate.
            // The particular discovery run is pinned separately in lineage.
            $seed = $goal->organizationId . "\0" . $goal->goalId . "\0" . $id;
            $planId = 'plan-' . substr(hash('sha256', $seed), 0, 24);
            $input = static fn (string $target, array $parameters): array => [
                'target_type' => 'growth_candidate',
                'target_id' => $target,
                'parameters' => $parameters,
            ];
            $step = static fn (string $name, string $capability, array $payload): array => [
                'id' => $name, 'capability_id' => $capability,
                'capability_version' => '1.0.0', 'input' => $payload,
            ];
            $steps = [
                $step('qualify', 'growth.candidate.qualify', $input($id, [
                    'policy_id' => $options['policy_id'],
                    'policy_revision' => $options['policy_revision'],
                ])),
                $step('prepare', 'growth.handoff.prepare', $input($id, [
                    'expected_value' => $options['expected_value'],
                    'recommended_play' => $options['recommended_play'],
                    'recommended_action' => $options['recommended_action'],
                ])),
                $step('handoff', 'growth.handoff.target.sales', $input($id, [
                    'target_domain' => 'sales',
                ])),
                $step('proposal', 'documents.proposal.prepare', $input($id, [
                    'template_id' => $options['template_id'],
                ])),
            ];
            $evidence = [
                'schema_version' => 1,
                'source_federation_run' => $options['source_federation_run'],
                'source_federation_step' => $options['source_federation_step'],
                'native_discovery_run' => $run['run_id'],
                'candidate_id' => $id,
                'account_id' => $subject['account_id'],
                'source_hash' => $subject['source_hash'],
            ];
            $plans[] = [
                'plan_id' => $planId, 'candidate_id' => $id,
                'steps' => $steps,
                'lineage' => $evidence + ['evidence_hash' => hash('sha256',
                    json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))],
            ];
        }
        return [
            'ready' => $ready,
            'requested' => $requested,
            'eligible' => count($eligible),
            'proposed_count' => count($plans),
            'plans' => $plans,
            'business_outcome_verified' => false,
            'reason' => $ready ? null : 'insufficient_scored_candidates',
        ];
    }

    private static function instant(mixed $date): ?int
    {
        if (!is_string($date) || trim($date) === '') return null;
        try { return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { return null; }
    }
}
