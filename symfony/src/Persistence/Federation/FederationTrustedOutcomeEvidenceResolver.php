<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;
use Platform\Orchestration\Goal\GoalSpecification;

/**
 * Only explicitly registered Domain-owned read models may create persisted
 * Goal observations. Missing or disabled Domains remain unverifiable.
 */
final readonly class FederationTrustedOutcomeEvidenceResolver
{
    /** @param iterable<GoalOutcomeEvidenceProviderInterface> $providers */
    public function __construct(
        private ActiveModuleResolver $modules,
        private iterable $providers,
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function collect(GoalSpecification $goal, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            throw new DomainException('Outcome evaluation interval must be positive.');
        }
        $result = [];
        foreach ($goal->criteria as $criterion) {
            $id = $criterion['id'];
            $owner = null;
            foreach ($this->providers as $provider) {
                if (!$provider instanceof GoalOutcomeEvidenceProviderInterface) {
                    throw new DomainException('Untrusted Goal evidence provider registration.');
                }
                if (!$provider->supports($id)) continue;
                if ($owner !== null) {
                    throw new DomainException('Ambiguous Domain ownership of Goal outcome criterion.');
                }
                $owner = $provider;
            }
            if ($owner === null || !$this->modules->isEnabled($goal->organizationId, $owner->domain())) {
                // Unsupported or unavailable metric is NOT a zero and is
                // never automatically counted as successful.
                continue;
            }
            $observation = $owner->observe($goal->organizationId, $id, $from, $to);
            if (!is_array($observation)
                || !is_scalar($observation['value'] ?? null)
                || !is_array($observation['evidence'] ?? null)
                || $observation['evidence'] === []
                || !is_string($observation['source'] ?? null)
                || $observation['source'] === ''
                || ($observation['window_start'] ?? null) !== $from->format('Y-m-d\TH:i:s.uP')
                || ($observation['window_end'] ?? null) !== $to->format('Y-m-d\TH:i:s.uP')) {
                throw new DomainException('Trusted Domain evidence has invalid provenance.');
            }
            foreach ($observation['evidence'] as $reference) {
                if (!is_string($reference) || $reference === '') {
                    throw new DomainException('Trusted Domain evidence contains an invalid reference.');
                }
            }
            $result[$id] = $observation;
        }
        return $result;
    }
}
