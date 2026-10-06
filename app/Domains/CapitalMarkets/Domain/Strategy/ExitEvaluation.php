<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

use Kernel\Shared\Domain\ValueObject;

final readonly class ExitEvaluation extends ValueObject
{
    /** @param list<string> $reasons */
    public function __construct(public ExitDecision $decision,public array $reasons=[]){}
}
