<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Result;

final readonly class Coverage
{
    /** @param list<string> $missingRequired */
    public function __construct(public float $ratio, public string $level, public array $missingRequired = [])
    {
    }
}
