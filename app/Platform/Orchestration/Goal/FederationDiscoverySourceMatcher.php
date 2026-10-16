<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Independently compares immutable child-Plan source identity to the current
 * tenant-owned Growth discovery read model. No mutation or Action admission.
 *
 * Growth memberships are mutable: this proves current native provenance, not
 * an immutable historical membership snapshot or external website truth.
 */
final readonly class FederationDiscoverySourceMatcher
{
    /** @param array<string,mixed> $brief */
    public function assertMatch(
        string $organizationId,
        string $universeId,
        string $nativeRunId,
        string $candidateId,
        string $accountId,
        string $sourceHash,
        array $brief,
    ): void {
        $universe = $brief['universe'] ?? null;
        $runs = $brief['runs'] ?? null;
        $memberships = $brief['memberships'] ?? null;
        if (!is_array($universe)
            || ($universe['organization_id'] ?? null) !== $organizationId
            || ($universe['universe_id'] ?? null) !== $universeId
            || ($universe['target_domain'] ?? null) !== 'sales'
            || !is_array($runs) || !array_is_list($runs) || count($runs) > 20
            || !is_array($memberships) || !array_is_list($memberships)
            || count($memberships) > 200
            || !preg_match('/^[a-f0-9]{64}$/D', $sourceHash)) {
            throw new DomainException('Untrusted native Growth source snapshot.');
        }
        $runMatches = 0;
        foreach ($runs as $run) {
            if (!is_array($run)) throw new DomainException('Malformed Growth source Run.');
            if (($run['run_id'] ?? null) === $nativeRunId) {
                if (($run['organization_id'] ?? null) !== $organizationId
                    || ($run['universe_id'] ?? null) !== $universeId
                    || ($run['status'] ?? null) !== 'completed') {
                    throw new DomainException('Native Growth source Run is not finalized.');
                }
                ++$runMatches;
            }
        }
        if ($runMatches !== 1) {
            throw new DomainException('Expected one completed native Growth source Run.');
        }
        $matches = 0;
        foreach ($memberships as $membership) {
            if (!is_array($membership)
                || ($membership['organization_id'] ?? null) !== $organizationId
                || ($membership['universe_id'] ?? null) !== $universeId) {
                throw new DomainException('Cross-tenant or malformed Growth membership.');
            }
            if (($membership['candidate_id'] ?? null) !== $candidateId) continue;
            if (($membership['account_id'] ?? null) !== $accountId
                || ($membership['external_key_hash'] ?? null) !== $sourceHash
                || !is_string($membership['source_reference'] ?? null)
                || trim($membership['source_reference']) === '') {
                throw new DomainException('Growth Candidate source lineage differs from native membership.');
            }
            ++$matches;
        }
        if ($matches !== 1) {
            throw new DomainException('Growth Candidate has no unique source-attested membership.');
        }
    }
}
