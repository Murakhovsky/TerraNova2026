<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use DomainException;

final class EconomicRelationshipGraph
{
    /** @var array<string,EconomicRelationship> */
    private array $relationships = [];

    public function add(EconomicRelationship $relationship): void
    {
        $key = $relationship->key();
        $existing = $this->relationships[$key] ?? null;

        if ($existing !== null && !$existing->equals($relationship)) {
            throw new DomainException('Economic relationship key is already defined with different evidence.');
        }

        $this->relationships[$key] = $relationship;
    }

    /** @return list<EconomicRelationship> */
    public function all(): array
    {
        return array_values($this->relationships);
    }

    /** @return list<EconomicRelationship> */
    public function outgoing(InstrumentId $instrumentId): array
    {
        return array_values(array_filter(
            $this->relationships,
            static fn (EconomicRelationship $relationship): bool => $relationship->from->equals($instrumentId),
        ));
    }

    /** @return list<EconomicRelationship> */
    public function between(InstrumentId $left, InstrumentId $right): array
    {
        return array_values(array_filter(
            $this->relationships,
            static fn (EconomicRelationship $relationship): bool =>
                ($relationship->from->equals($left) && $relationship->to->equals($right))
                || ($relationship->from->equals($right) && $relationship->to->equals($left)),
        ));
    }
}
