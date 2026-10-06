<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Opportunity;

use DateTimeImmutable;

interface OpportunityCandidateInterface
{
    public function candidateId():string;
    public function hypothesisCode():HypothesisCode;
    public function expiredAt(DateTimeImmutable $at):bool;
}
