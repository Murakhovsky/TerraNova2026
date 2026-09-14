<?php
declare(strict_types=1);

namespace Domains\Property\Model;

final readonly class PropertyIdentityResolution
{
    /** @param list<string> $reasons */
    public function __construct(
        public PropertyIdentityResolutionDecision $decision,
        public float $score,
        public array $reasons = [],
        public ?PropertyIdentity $candidateIdentity = null,
    ) {}
}
