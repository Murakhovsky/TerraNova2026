<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class OpportunityScore
{
    public function __construct(
        public ScoreDimension $fit,
        public ScoreDimension $need,
        public ScoreDimension $timing,
        public ScoreDimension $access,
        public ScoreDimension $value,
        public float $confidence,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Growth OpportunityScore confidence must be between 0 and 1.');
        }
    }

    /**
     * There is deliberately no magical single canonical score here.
     * Consumers may rank dimensions explicitly according to their business policy.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fit' => $this->fit->toArray(),
            'need' => $this->need->toArray(),
            'timing' => $this->timing->toArray(),
            'access' => $this->access->toArray(),
            'value' => $this->value->toArray(),
            'confidence' => $this->confidence,
        ];
    }
}
