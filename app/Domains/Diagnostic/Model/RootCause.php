<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use InvalidArgumentException;
final readonly class RootCause
{
    /** @param list<string> $relatedFindings @param list<string> $supportingEvidence @param list<string> $causalPath */
    public function __construct(public string $id, public string $description, public array $relatedFindings, public array $supportingEvidence, public float $confidence, public array $causalPath)
    { if ($id==='' || $description==='' || $relatedFindings===[] || $causalPath===[] || $confidence<0 || $confidence>1) throw new InvalidArgumentException('Invalid root cause.'); }
}
