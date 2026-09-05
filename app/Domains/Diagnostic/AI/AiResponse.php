<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;

final readonly class AiResponse
{
    public function __construct(public array $output, public int $tokensInput=0, public int $tokensOutput=0, public float $estimatedCost=0, public int $durationMs=0, public string $model='fake', public string $status='SUCCESS') {}
}
