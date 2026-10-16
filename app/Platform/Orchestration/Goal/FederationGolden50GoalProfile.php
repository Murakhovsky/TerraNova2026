<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * The one explicit Package A golden reference Goal. Capability allowlisting
 * only controls Plan proposal; real execution still requires live domain
 * bindings, tenant activation and independent human Plan/Action approvals.
 */
final readonly class FederationGolden50GoalProfile
{
    public const METRIC = 'federation.verified_proposals';
    public const TARGET = 50;

    /** @return list<string> */
    public static function capabilities(string $criterion, int $target): array
    {
        if ($criterion !== self::METRIC) return [];
        if ($target !== self::TARGET) {
            throw new DomainException('Package A reference Goal must specify exactly fifty verified native proposals.');
        }
        return [
            'growth.market.discovery',
            'growth.candidate.qualify',
            'growth.handoff.prepare',
            'growth.handoff.target.sales',
            'documents.proposal.prepare',
        ];
    }
}
