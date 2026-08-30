<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Input;

final readonly class ObservedValue
{
    /** @param list<EvidenceSignal> $evidence */
    public function __construct(public mixed $value, public array $evidence = [])
    {
    }
}
